<?php

namespace App\Services\SpamPolicy;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Casts\MailcowDateTime;
use App\Exceptions\ProblemException;
use App\Policies\SpamPolicyPolicy;
use App\Services\Access\SpamFilterScope;
use App\Services\Audit\AuditLogger;
use App\Support\Database\NamedLock;
use App\Support\Domain\DomainName;
use App\Support\Http\ETag;
use App\Support\Http\ListQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * The allow and block lists of domains, alias domains and mailboxes (feature 006 research R11,
 * FR-043 to FR-049): legacy's `filterconf` rows `whitelist_from` (allow) and `blacklist_from`
 * (block), which Rspamd's settings map applies.
 *
 *  - Views (legacy policy('get')): a domain's or alias domain's own entries plus, read-only, the
 *    entries of its mailboxes (`object LIKE '%@<name>'`); a mailbox's own entries plus, read-only,
 *    its domain's entries. Filters, search, sorting and paging run in SQL.
 *  - Creation: the pattern is trimmed and lower-cased; a pattern on either list of the same owner is
 *    `409 spam-policy-entry-exists`. The check and the insert run under the MariaDB lock
 *    `restv2_filterconf_<sha1 of the owner>` (conventions: uniqueness that upstream enforces only in
 *    code), so concurrent identical additions store one row.
 *  - Removal only through the owner; another owner's entry is `404`.
 *
 * Audit records target the owner (G15), with the entry's ID, list and pattern in `details`.
 */
class SpamPolicyEntryService
{
    public const LISTS = ['allow' => 'whitelist_from', 'block' => 'blacklist_from'];

    /** Contract sort value => column. */
    public const SORTS = ['pattern' => 'value', 'createdAt' => 'created', 'id' => 'prefid'];

    public function __construct(
        private readonly SpamFilterScope $scope,
        private readonly SpamPolicyPolicy $policy,
        private readonly NamedLock $locks,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The owner of a path: its kind and normalised name, after the role and scope checks.
     *
     * @return array{kind: string, name: string}
     */
    public function owner(Principal $principal, string $kind, string $raw, bool $change): array
    {
        $this->policy->authorize($principal, match (true) {
            $kind === 'mailbox' && $change => SpamPolicyPolicy::CHANGE_MAILBOX_ENTRIES,
            $kind === 'mailbox' => SpamPolicyPolicy::VIEW_MAILBOX_ENTRIES,
            $change => SpamPolicyPolicy::CHANGE_DOMAIN_ENTRIES,
            default => SpamPolicyPolicy::VIEW_DOMAIN_ENTRIES,
        });

        return ['kind' => $kind, 'name' => match ($kind) {
            'domain' => $this->scope->domain($principal, $raw),
            'aliasDomain' => $this->scope->aliasDomain($principal, $raw),
            default => $this->scope->mailbox($principal, $raw),
        }];
    }

    /**
     * @param  array{kind: string, name: string}  $owner
     * @param  array{list: string|null, ownership: string|null}  $filters
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}
     */
    public function page(array $owner, ListQuery $list, array $filters): array
    {
        $related = $this->related($owner);
        $query = DB::table('filterconf')->whereIn('option', array_values(self::LISTS));

        $query->where(function (Builder $inner) use ($owner, $related, $filters): void {
            if (($filters['ownership'] ?? null) !== 'inherited') {
                $inner->orWhere('object', $owner['name']);
            }

            if (($filters['ownership'] ?? null) !== 'own') {
                $related['exact'] === null
                    ? $inner->orWhere('object', 'like', '%@'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $owner['name']))
                    : $inner->orWhere('object', $related['exact']);
            }
        });

        if (is_string($filters['list'] ?? null)) {
            $query->where('option', self::LISTS[$filters['list']]);
        }

        $list->applySearch($query, ['value']);
        $total = (clone $query)->count();
        $query->orderBy(self::SORTS[$list->sort], $list->order);

        if ($list->sort !== 'id') {
            $query->orderBy('prefid');
        }

        $rows = $query->offset($list->offset)->limit($list->limit)->get();

        return [
            'data' => array_values(array_map(fn (stdClass $row): array => $this->present($owner, $row), $rows->all())),
            'meta' => $list->meta($total),
        ];
    }

    /**
     * @param  array{kind: string, name: string}  $owner
     * @return array<string, mixed>
     */
    public function find(array $owner, int $id): array
    {
        return $this->present($owner, $this->ownRow($owner, $id) ?? throw new NotFoundHttpException('The entry does not exist.'));
    }

    /**
     * @param  array{kind: string, name: string}  $owner
     * @return array<string, mixed>
     */
    public function create(Principal $principal, array $owner, string $list, string $pattern): array
    {
        $option = self::LISTS[$list];
        $name = $owner['name'];

        // A lock timeout is an unexpected failure (`500`, as 003's address lock); the contract
        // documents no temporary failure for list entries.
        $id = $this->locks->run(['restv2_filterconf_'.sha1($name)], fn (): int => DB::transaction(function () use ($principal, $owner, $name, $option, $list, $pattern): int {
            $existing = DB::table('filterconf')->where('object', $name)->where('value', $pattern)->whereIn('option', array_values(self::LISTS))->orderBy('prefid')->first(['prefid', 'option']);

            if ($existing !== null) {
                throw ProblemException::spamPolicyEntryExists((string) array_search($existing->option, self::LISTS, true), (int) $existing->prefid);
            }

            $id = (int) DB::table('filterconf')->insertGetId(['object' => $name, 'option' => $option, 'value' => $pattern]);
            $this->audit->record(AuditEventType::SpamPolicyEntryCreation, true, $principal, self::target($owner), null, null, ['entryId' => $id, 'list' => $list, 'pattern' => $pattern]);

            return $id;
        }), 10);

        return $this->find($owner, $id);
    }

    /**
     * @param  array{kind: string, name: string}  $owner
     */
    public function delete(Principal $principal, array $owner, int $id, ?string $ifMatch): void
    {
        DB::transaction(function () use ($principal, $owner, $id, $ifMatch): void {
            $row = $this->ownRow($owner, $id, lock: true) ?? throw new NotFoundHttpException('The entry does not exist.');

            if (ETag::preconditionFails($ifMatch, self::etag($row))) {
                throw new PreconditionFailedHttpException('The entry does not match the given version.');
            }

            DB::table('filterconf')->where('prefid', $id)->delete();
            $this->audit->record(AuditEventType::SpamPolicyEntryRemoval, true, $principal, self::target($owner), null, null, [
                'entryId' => $id,
                'list' => (string) array_search($row->option, self::LISTS, true),
                'pattern' => (string) $row->value,
            ]);
        });
    }

    /**
     * The path of an entry under its owner.
     *
     * @param  array{kind: string, name: string}  $owner
     */
    public static function location(array $owner, int $id): string
    {
        $collection = ['domain' => 'domains', 'aliasDomain' => 'alias-domains', 'mailbox' => 'mailboxes'][$owner['kind']];

        return "/api/v2/{$collection}/{$owner['name']}/spam-policy-entries/{$id}";
    }

    /**
     * The audit target: the owner (G15).
     *
     * @param  array{kind: string, name: string}  $owner
     * @return array{type: string, id: string, name: string}
     */
    public static function target(array $owner): array
    {
        return ['type' => $owner['kind'], 'id' => $owner['name'], 'name' => $owner['kind'] === 'mailbox' ? $owner['name'] : DomainName::toUnicode($owner['name'])];
    }

    /**
     * The related, read-only entries of a view: a mailbox's domain (`exact`), or for a domain and
     * an alias domain the entries of its mailboxes (`exact` null: `LIKE '%@<name>'`).
     *
     * @param  array{kind: string, name: string}  $owner
     * @return array{exact: string|null}
     */
    private function related(array $owner): array
    {
        return ['exact' => $owner['kind'] === 'mailbox' ? (string) DB::table('mailbox')->where('username', $owner['name'])->value('domain') : null];
    }

    /**
     * @param  array{kind: string, name: string}  $owner
     */
    private function ownRow(array $owner, int $id, bool $lock = false): ?stdClass
    {
        $query = DB::table('filterconf')->where('prefid', $id)->where('object', $owner['name'])->whereIn('option', array_values(self::LISTS));
        $row = ($lock ? $query->lockForUpdate() : $query)->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * @param  array{kind: string, name: string}  $owner
     * @return array<string, mixed>
     */
    private function present(array $owner, stdClass $row): array
    {
        $object = (string) $row->object;
        $own = strcasecmp($object, $owner['name']) === 0;

        return [
            'id' => (int) $row->prefid,
            'list' => (string) array_search($row->option, self::LISTS, true),
            'owner' => ['kind' => $own ? $owner['kind'] : (str_contains($object, '@') ? 'mailbox' : 'domain'), 'name' => $object],
            'pattern' => (string) $row->value,
            'createdAt' => MailcowDateTime::toUtcString($row->created) ?? '1970-01-01T00:00:00Z',
            'own' => $own,
            'etag' => self::etag($row),
        ];
    }

    private static function etag(stdClass $row): string
    {
        return ETag::of(['spamPolicyEntry', (int) $row->prefid, (string) $row->object, (string) $row->option, (string) $row->value]);
    }
}
