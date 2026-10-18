<?php

namespace App\Services\TemporaryAliases;

use App\Audit\AuditEventType;
use App\Auth\PermissionFlag;
use App\Auth\Principal;
use App\Casts\MailcowDateTime;
use App\Services\Account\MailboxOverviewService;
use App\Services\Aliases\AliasAddressAvailability;
use App\Services\Audit\AuditLogger;
use App\Services\Mailboxes\AddressLock;
use App\Services\SelfService\PermissionGate;
use App\Support\Domain\DomainName;
use App\Support\Http\ETag;
use App\Support\Http\FieldViolations;
use App\Support\Http\ListQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * The temporary (spam) aliases of a mailbox (feature 005 FR-042–FR-046, FR-081, research R12),
 * stored in `spamalias` as legacy mailbox('add' | 'edit' | 'delete', 'time_limited_alias') stores
 * them, with the spec's fixes:
 *
 *  - the domain is the mailbox's domain or one of its alias domains (legacy user_get_alias_details());
 *  - addresses are readable random addresses from a secure source, never equal to an address in
 *    use: every candidate is checked and inserted under 003's shared address lock
 *    (docs/conventions.md), against mailboxes, resources, aliases, temporary aliases and, in an alias
 *    domain, the target domain's mailbox or alias with the same local part;
 *  - lifetimes of 1 to 87,600 hours also on change; permanence keeps legacy's columns;
 *  - an expired alias (`validity` passed and not permanent) does not exist for any operation, as the
 *    mail server and legacy's list treat it; mailcow's daily clean-up removes it;
 *  - changes need `spamAlias`, reads need no flag.
 */
class TemporaryAliasService
{
    public const SORT_COLUMNS = ['address' => 'address', 'expiresAt' => 'validity', 'createdAt' => 'created'];

    public function __construct(
        private readonly PermissionGate $gate,
        private readonly TemporaryAliasAddressGenerator $generator,
        private readonly AddressLock $locks,
        private readonly AliasAddressAvailability $availability,
        private readonly MailboxOverviewService $overview,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The domains available for the mailbox's temporary aliases: its domain first, then its alias
     * domains by name (FR-081).
     *
     * @return list<array{domain: string, domainUnicode: string, kind: string}>
     */
    public function domains(string $mailbox): array
    {
        $domain = (string) DB::table('mailbox')->where('username', $mailbox)->value('domain');
        $entries = [['domain' => $domain, 'domainUnicode' => DomainName::toUnicode($domain), 'kind' => 'mailboxDomain']];

        foreach ($this->overview->aliasDomains($domain) as $aliasDomain) {
            $entries[] = ['domain' => $aliasDomain, 'domainUnicode' => DomainName::toUnicode($aliasDomain), 'kind' => 'aliasDomain'];
        }

        return $entries;
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}
     */
    public function list(string $mailbox, ListQuery $query, ?bool $permanent): array
    {
        $builder = $this->visible($mailbox);

        if ($permanent !== null) {
            $permanent ? $builder->where('permanent', '!=', 0) : $builder->where('permanent', 0);
        }

        $query->applySearch($builder, ['address', 'description']);
        $total = (clone $builder)->count();

        if ($query->sort === 'expiresAt') {
            $builder->orderByRaw('(`permanent` != 0) '.($query->order === 'desc' ? 'desc' : 'asc'))->orderBy('validity', $query->order);
        } else {
            $builder->orderBy($query->sortColumn(), $query->order);
        }

        $rows = $builder->orderBy('address', $query->order)->offset($query->offset)->limit($query->limit)->get();

        return ['data' => array_values($rows->map(fn (object $row): array => $this->present($row))->all()), 'meta' => $query->meta($total)];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $mailbox, string $address): ?array
    {
        $row = $this->visible($mailbox)->where('address', self::normalize($address))->first();

        return $row === null ? null : $this->present($row);
    }

    /**
     * @param  array<string, mixed>  $input  validated `TemporaryAliasCreateRequest` members
     * @return array<string, mixed> contract `TemporaryAlias`
     */
    public function create(Principal $principal, string $mailbox, array $input): array
    {
        $this->gate->requireChange($principal, PermissionFlag::SpamAlias);

        $violations = new FieldViolations;
        $domains = array_column($this->domains($mailbox), 'domain');
        $domain = DomainName::normalizeValid((string) $input['domain']);

        if ($domain === null || ! in_array($domain, $domains, true)) {
            $violations->add('domain', 'notInList', ['allowed' => $domains]);
        }

        $violations->throwIfAny();

        $description = (string) ($input['description'] ?? '');
        $permanent = ($input['permanent'] ?? false) === true;
        $hours = (int) ($input['lifetimeHours'] ?? 8760);
        $validity = CarbonImmutable::now()->getTimestamp() + $hours * 3600;
        $mailboxDomain = $domains[0];

        $address = $this->generator->generate((string) $domain, fn (string $candidate): bool => $this->claim($principal, $mailbox, $candidate, (string) $domain, $mailboxDomain, $description, $validity, $permanent));

        return $this->find($mailbox, $address) ?? throw new NotFoundHttpException;
    }

    /**
     * @param  array{lifetimeHours?: int, permanent?: bool}  $patch
     * @return array<string, mixed> contract `TemporaryAlias`
     */
    public function update(Request $request, Principal $principal, string $mailbox, string $address, array $patch): array
    {
        $this->gate->requireChange($principal, PermissionFlag::SpamAlias);
        $address = self::normalize($address);

        DB::transaction(function () use ($request, $principal, $mailbox, $address, $patch): void {
            $row = $this->visible($mailbox)->where('address', $address)->lockForUpdate()->first() ?? throw new NotFoundHttpException('The temporary alias does not exist.');

            if (ETag::ifMatchFails($request, $this->etag($row))) {
                throw new PreconditionFailedHttpException('The temporary alias has changed.');
            }

            [$validity, $permanent] = isset($patch['lifetimeHours'])
                ? [CarbonImmutable::now()->getTimestamp() + (int) $patch['lifetimeHours'] * 3600, 0]
                : [0, 1];

            DB::table('spamalias')->where('address', $row->address)->where('goto', $mailbox)->update(['validity' => $validity, 'permanent' => $permanent]);

            $old = $this->present($row);
            $new = ['permanent' => $permanent === 1, 'expiresAt' => $permanent === 1 ? null : self::time($validity)];
            $changes = [];

            foreach ($new as $key => $value) {
                if ($old[$key] !== $value) {
                    $changes[$key] = ['old' => $old[$key], 'new' => $value];
                }
            }

            $this->audit->record(AuditEventType::TemporaryAliasUpdate, true, $principal, ['type' => 'temporaryAlias', 'id' => $mailbox, 'name' => (string) $row->address], null, null, [
                'temporaryAlias' => (string) $row->address,
                'changes' => $changes,
            ]);
        });

        return $this->find($mailbox, $address) ?? throw new NotFoundHttpException;
    }

    public function delete(Request $request, Principal $principal, string $mailbox, string $address): void
    {
        $this->gate->requireChange($principal, PermissionFlag::SpamAlias);
        $address = self::normalize($address);

        DB::transaction(function () use ($request, $principal, $mailbox, $address): void {
            $row = $this->visible($mailbox)->where('address', $address)->lockForUpdate()->first() ?? throw new NotFoundHttpException('The temporary alias does not exist.');

            if (ETag::ifMatchFails($request, $this->etag($row))) {
                throw new PreconditionFailedHttpException('The temporary alias has changed.');
            }

            DB::table('spamalias')->where('address', $row->address)->where('goto', $mailbox)->delete();
            $old = $this->present($row);

            $this->audit->record(AuditEventType::TemporaryAliasDeletion, true, $principal, ['type' => 'temporaryAlias', 'id' => $mailbox, 'name' => (string) $row->address], null, null, [
                'temporaryAlias' => (string) $row->address,
                'removed' => ['description' => $old['description'], 'permanent' => $old['permanent'], 'expiresAt' => $old['expiresAt']],
            ]);
        });
    }

    /**
     * Checks a candidate address and inserts it with its audit record, under the shared address
     * lock (and, in an alias domain, the lock of the target-domain address); false when taken.
     */
    private function claim(Principal $principal, string $mailbox, string $address, string $domain, string $mailboxDomain, string $description, int $validity, bool $permanent): bool
    {
        $local = substr($address, 0, (int) strrpos($address, '@'));
        $targetAddress = $domain === $mailboxDomain ? null : $local.'@'.$mailboxDomain;
        $names = $targetAddress === null ? [$address] : [$address, $targetAddress];

        return $this->locks->run($names, fn (): bool => DB::transaction(function () use ($principal, $mailbox, $address, $targetAddress, $description, $validity, $permanent): bool {
            if ($this->availability->holderOf($address) !== null
                || ($targetAddress !== null && ($this->availability->holderOf($targetAddress) !== null))) {
                return false;
            }

            try {
                DB::table('spamalias')->insert(['address' => $address, 'description' => $description, 'goto' => $mailbox, 'validity' => $validity, 'permanent' => (int) $permanent]);
            } catch (QueryException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                    return false;
                }

                throw $e;
            }

            $this->audit->record(AuditEventType::TemporaryAliasCreation, true, $principal, ['type' => 'temporaryAlias', 'id' => $mailbox, 'name' => $address], null, null, [
                'temporaryAlias' => $address,
                'values' => ['description' => $description, 'permanent' => $permanent, 'expiresAt' => $permanent ? null : self::time($validity)],
            ]);

            return true;
        }));
    }

    /**
     * The mailbox's temporary aliases that are permanent or not yet expired.
     */
    private function visible(string $mailbox): Builder
    {
        $now = CarbonImmutable::now()->getTimestamp();

        return DB::table('spamalias')->where('goto', $mailbox)->where(fn (Builder $q) => $q->where('validity', '>=', $now)->orWhere('permanent', '!=', 0));
    }

    private static function normalize(string $address): string
    {
        $address = trim($address);
        $at = strrpos($address, '@');

        if ($at === false) {
            return mb_strtolower($address);
        }

        $domain = DomainName::normalizeValid(substr($address, $at + 1));

        return mb_strtolower(substr($address, 0, $at)).'@'.($domain ?? mb_strtolower(substr($address, $at + 1)));
    }

    private static function time(int $unix): string
    {
        return CarbonImmutable::createFromTimestampUTC($unix)->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @return array<string, mixed> contract `TemporaryAlias`
     */
    private function present(object $row): array
    {
        $permanent = (int) $row->permanent !== 0;

        return [
            'address' => (string) $row->address,
            'mailbox' => (string) $row->goto,
            'description' => (string) $row->description,
            'permanent' => $permanent,
            'expiresAt' => $permanent || $row->validity === null ? null : self::time((int) $row->validity),
            'createdAt' => (string) MailcowDateTime::toUtcString($row->created),
            'modifiedAt' => MailcowDateTime::toUtcString($row->modified),
            'etag' => $this->etag($row),
        ];
    }

    private function etag(object $row): string
    {
        return ETag::of(['temporaryAlias', (string) $row->address, (string) $row->goto, (string) $row->description, $row->validity === null ? null : (int) $row->validity, (int) $row->permanent]);
    }
}
