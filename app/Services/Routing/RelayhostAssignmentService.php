<?php

namespace App\Services\Routing;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Support\Http\ETag;
use App\Support\Http\FieldViolations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * Relayhost assignments of domains and mailboxes (feature 009 FR-024–FR-030, research R11), stored
 * where legacy stores them so that Postfix's sender-dependent lookups apply them unchanged:
 * `domain.relayhost` (a decimal ID as a string, `'0'` for none) and the mailbox's
 * `attributes.relayhost` (a JSON string, `"0"` for none). A replacement writes only that value
 * (MariaDB moves `modified`); unlike legacy it runs no groupware rebuild, no cache flush and no
 * `attribute_hash` reset (spec parity table). The caller's role, flag and scope are checked before
 * (RoutingPolicy, DomainScope, MailboxScope).
 *
 * A mailbox's representation also shows the relayhost the mail server connects to for its own
 * address (the mailbox's when set and active, else the domain's when set and active) and the one
 * whose credentials it sends (`mysql_sasl_passwd_maps_sender_dependent.cf` takes the domain's active
 * relayhost first, then the mailbox's, and logs in only when that row has a username).
 */
class RelayhostAssignmentService
{
    public function __construct(private readonly RoutingAudit $audit) {}

    /**
     * Contract `DomainRelayhostAssignment` of an existing domain (ASCII name).
     *
     * @return array<string, mixed>
     */
    public function domain(string $domain): array
    {
        $row = DB::table('domain')->where('domain', $domain)->first(['domain', 'relayhost']) ?? throw new NotFoundHttpException('The domain does not exist.');
        $reference = $this->references([RelayhostReference::storedId($row->relayhost)])[0];

        return [
            'domain' => (string) $row->domain,
            'relayhost' => $reference?->toArray(),
            'etag' => self::domainVersion($row),
        ];
    }

    /**
     * Contract `MailboxRelayhostAssignment` of an existing mailbox.
     *
     * @return array<string, mixed>
     */
    public function mailbox(string $address): array
    {
        $row = $this->mailboxRow($address) ?? throw new NotFoundHttpException('The mailbox does not exist.');
        $domainValue = DB::table('domain')->where('domain', $row->domain)->value('relayhost');
        [$own, $domain] = $this->references([RelayhostReference::storedId($row->relayhost), RelayhostReference::storedId($domainValue)]);

        $effective = match (true) {
            $own?->isActive() === true => ['id' => $own->id, 'host' => (string) $own->host, 'source' => 'mailbox'],
            $domain?->isActive() === true => ['id' => $domain->id, 'host' => (string) $domain->host, 'source' => 'domain'],
            default => null,
        };

        [$credentials, $source] = match (true) {
            $domain?->isActive() === true => [$domain, 'domain'],
            $own?->isActive() === true => [$own, 'mailbox'],
            default => [null, null],
        };

        return [
            'mailbox' => (string) $row->username,
            'domain' => (string) $row->domain,
            'relayhost' => $own?->toArray(),
            'domainRelayhost' => $domain?->toArray(),
            'effectiveRelayhost' => $effective,
            'credentialsRelayhost' => $credentials !== null && $credentials->username !== '' ? ['id' => $credentials->id, 'source' => $source] : null,
            'etag' => self::mailboxVersion($row),
        ];
    }

    public function replaceDomain(Principal $principal, string $domain, ?int $relayhostId, ?string $ifMatch): void
    {
        DB::transaction(function () use ($principal, $domain, $relayhostId, $ifMatch): void {
            $row = DB::table('domain')->where('domain', $domain)->lockForUpdate()->first(['domain', 'relayhost']) ?? throw new NotFoundHttpException('The domain does not exist.');

            if (ETag::preconditionFails($ifMatch, self::domainVersion($row))) {
                throw new PreconditionFailedHttpException('The domain\'s relayhost assignment was changed since it was read.');
            }

            $this->requireRelayhost($relayhostId);
            $old = RelayhostReference::storedId($row->relayhost);
            $changes = [];

            if ($old !== $relayhostId) {
                DB::table('domain')->where('domain', $domain)->update(['relayhost' => (string) ($relayhostId ?? 0)]);
                $changes['relayhostId'] = ['old' => $old, 'new' => $relayhostId];
            }

            $this->audit->success(AuditEventType::RelayhostAssignmentUpdate, $principal, RoutingAudit::target('domainRelayhostAssignment', $domain, $domain), ['changes' => (object) $changes]);
        });
    }

    public function replaceMailbox(Principal $principal, string $address, ?int $relayhostId, ?string $ifMatch): void
    {
        DB::transaction(function () use ($principal, $address, $relayhostId, $ifMatch): void {
            $row = $this->mailboxRow($address, true) ?? throw new NotFoundHttpException('The mailbox does not exist.');

            if (ETag::preconditionFails($ifMatch, self::mailboxVersion($row))) {
                throw new PreconditionFailedHttpException('The mailbox\'s relayhost assignment was changed since it was read.');
            }

            $this->requireRelayhost($relayhostId);
            $old = RelayhostReference::storedId($row->relayhost);
            $changes = [];

            if ($old !== $relayhostId) {
                DB::update("UPDATE `mailbox` SET `attributes` = JSON_SET(`attributes`, '$.relayhost', ?) WHERE `username` = ?", [(string) ($relayhostId ?? 0), $address]);
                $changes['relayhostId'] = ['old' => $old, 'new' => $relayhostId];
            }

            $this->audit->success(AuditEventType::RelayhostAssignmentUpdate, $principal, RoutingAudit::target('mailboxRelayhostAssignment', $address, $address), ['changes' => (object) $changes]);
        });
    }

    /** The version tag of a domain's stored assignment. */
    public static function domainVersion(object $row): string
    {
        return ETag::of(['domainRelayhostAssignment', (string) $row->domain, (string) $row->relayhost]);
    }

    /** The version tag of a mailbox's stored assignment (a missing attribute reads as ""). */
    public static function mailboxVersion(object $row): string
    {
        return ETag::of(['mailboxRelayhostAssignment', (string) $row->username, (string) ($row->relayhost ?? '')]);
    }

    /**
     * `422 notFound` on `relayhostId` unless it is none or an existing relayhost; the row is locked
     * against a concurrent deletion until the assignment is committed.
     */
    private function requireRelayhost(?int $relayhostId): void
    {
        if ($relayhostId !== null && ! DB::table('relayhosts')->where('id', $relayhostId)->sharedLock()->exists()) {
            $violations = new FieldViolations;
            $violations->add('relayhostId', 'notFound', [], 'The relayhost does not exist.');
            $violations->throwIfAny();
        }
    }

    private function mailboxRow(string $address, bool $lock = false): ?object
    {
        $query = DB::table('mailbox')->where('username', $address)->where('kind', '')
            ->select(['username', 'domain'])
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(`attributes`, '$.relayhost')) AS `relayhost`");

        return $lock ? $query->lockForUpdate()->first() : $query->first();
    }

    /**
     * The references of stored IDs, in one query; null for none.
     *
     * @param  list<int|null>  $ids
     * @return list<RelayhostReference|null>
     */
    private function references(array $ids): array
    {
        $wanted = array_values(array_filter($ids, fn (?int $id): bool => $id !== null));
        $rows = $wanted === [] ? collect() : DB::table('relayhosts')->whereIn('id', $wanted)->get(['id', 'hostname', 'username', 'active'])->keyBy('id');

        return array_map(function (?int $id) use ($rows): ?RelayhostReference {
            if ($id === null) {
                return null;
            }

            $row = $rows->get($id);

            return $row === null
                ? new RelayhostReference($id, null, 'dangling')
                : new RelayhostReference($id, (string) $row->hostname, (int) $row->active !== 0 ? 'active' : 'inactive', (string) $row->username);
        }, $ids);
    }
}
