<?php

namespace App\Services\Routing;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Support\Http\ETag;
use App\Support\Http\FieldViolations;
use App\Support\Routing\NextHop;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * Relayhost writes, legacy relayhost('add' / 'edit' / 'delete') (feature 009 FR-012–FR-014,
 * FR-018–FR-021, FR-083, research R4, R12), each in one transaction with its audit record:
 *
 *  - credentials are trimmed and stored verbatim, without legacy's colon escaping; an omitted
 *    password is kept, `""` removes it, an empty username without a password removes both, and a
 *    password with an empty resulting username is refused;
 *  - an edit writes only the columns that change, so legacy credentials with escaped colons stay
 *    byte-identical when neither is sent;
 *  - a deletion also clears every domain and mailbox assignment to the relayhost (legacy clears the
 *    domains only), atomically.
 *
 * No service is reloaded, as in legacy (FR-092): Postfix reads the table on every lookup.
 */
class RelayhostService implements RoutingBulkHandler
{
    /** Members of RelayhostPatch that change the relayhost. */
    public const WRITABLE = ['host', 'username', 'password', 'active'];

    public function __construct(
        private readonly RelayhostQuery $query,
        private readonly RoutingAudit $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $members  validated RelayhostCreateRequest members
     */
    public function create(Principal $principal, array $members): int
    {
        $host = NextHop::parse((string) $members['host'])->stored();
        $username = trim((string) ($members['username'] ?? ''));
        $password = trim((string) ($members['password'] ?? ''));
        $active = (bool) ($members['active'] ?? true);

        $violations = new FieldViolations;
        self::credentialViolations($username, $password, $violations);
        $violations->throwIfAny();

        return DB::transaction(function () use ($principal, $host, $username, $password, $active): int {
            $id = (int) DB::table('relayhosts')->insertGetId(['hostname' => $host, 'username' => $username, 'password' => $password, 'active' => (int) $active]);

            $this->audit->success(AuditEventType::RelayhostCreation, $principal, RoutingAudit::target('relayhost', $id, $host), ['values' => [
                'host' => $host,
                'username' => $username === '' ? null : $username,
                'password' => $password === '' ? null : 'set',
                'active' => $active,
            ]]);

            return $id;
        });
    }

    /**
     * @param  array<string, mixed>  $patch  the merge patch as sent (types validated)
     */
    public function update(Principal $principal, int $id, array $patch, ?string $ifMatch, ?string $bulkRequestId = null): void
    {
        DB::transaction(function () use ($principal, $id, $patch, $ifMatch, $bulkRequestId): void {
            $row = $this->lockedRow($id);

            if (ETag::preconditionFails($ifMatch, RelayhostQuery::version($row))) {
                throw new PreconditionFailedHttpException('The relayhost was changed since it was read.');
            }

            $violations = new FieldViolations;
            $changes = self::changes($row, $this->presented($id), $patch, $violations);
            $violations->throwIfAny();

            $columns = [];
            $details = [];

            foreach ($changes as $column => [$member, $old, $new]) {
                $columns[$column] = $new;
                $details[$member] = $member === 'password'
                    ? RoutingAudit::passwordChange((string) $old, (string) $new)
                    : ['old' => self::auditValue($member, $old), 'new' => self::auditValue($member, $new)];
            }

            if ($columns !== []) {
                DB::table('relayhosts')->where('id', $id)->update($columns);
            }

            $this->audit->success(
                AuditEventType::RelayhostUpdate,
                $principal,
                RoutingAudit::target('relayhost', $id, (string) ($columns['hostname'] ?? $row->hostname)),
                ['changes' => (object) $details],
                $bulkRequestId,
            );
        });
    }

    public function delete(Principal $principal, int $id, ?string $ifMatch, ?string $bulkRequestId = null): void
    {
        DB::transaction(function () use ($principal, $id, $ifMatch, $bulkRequestId): void {
            $row = $this->lockedRow($id);

            if (ETag::preconditionFails($ifMatch, RelayhostQuery::version($row))) {
                throw new PreconditionFailedHttpException('The relayhost was changed since it was read.');
            }

            $assigned = $this->query->assignedTo($id);

            DB::table('relayhosts')->where('id', $id)->delete();

            if ($assigned['domains'] !== []) {
                DB::table('domain')->whereIn('domain', $assigned['domains'])->update(['relayhost' => '0']);
            }

            if ($assigned['mailboxes'] !== []) {
                DB::table('mailbox')->whereIn('username', $assigned['mailboxes'])->update(['attributes' => DB::raw("JSON_SET(`attributes`, '$.relayhost', '0')")]);
            }

            $this->audit->success(AuditEventType::RelayhostDeletion, $principal, RoutingAudit::target('relayhost', $id, (string) $row->hostname), [
                'host' => (string) $row->hostname,
                'clearedDomains' => $assigned['domains'],
                'clearedMailboxes' => $assigned['mailboxes'],
            ], $bulkRequestId);
        });
    }

    public function version(int $id): ?string
    {
        $row = DB::table('relayhosts')->where('id', $id)->first();

        return $row === null ? null : RelayhostQuery::version($row);
    }

    /**
     * The violation of a password that would be stored with an empty username (FR-013).
     */
    public static function credentialViolations(string $username, string $password, FieldViolations $violations, string $field = 'username'): void
    {
        if ($password !== '' && $username === '') {
            $violations->add($field, 'required', ['because' => 'password']);
        }
    }

    /**
     * The changes a patch makes to a relayhost: column => [member, old, new], only for values that
     * differ; violations for read-only and unknown members and for the credential rule.
     *
     * @param  array<string, mixed>  $presented  the current representation
     * @param  array<string, mixed>  $patch
     * @return array<string, array{string, mixed, mixed}>
     */
    public static function changes(object $row, array $presented, array $patch, FieldViolations $violations): array
    {
        PatchMembers::check($patch, self::WRITABLE, array_intersect_key($presented, array_flip(['id', 'usage', 'passwordSet', 'etag'])), $violations);

        $username = array_key_exists('username', $patch) && is_string($patch['username']) ? trim($patch['username']) : (string) $row->username;
        $password = array_key_exists('password', $patch) && is_string($patch['password'])
            ? trim($patch['password'])
            : (array_key_exists('username', $patch) && $username === '' ? '' : (string) $row->password);

        if (array_key_exists('username', $patch) || array_key_exists('password', $patch)) {
            self::credentialViolations($username, $password, $violations);
        }

        $new = [
            'hostname' => ['host', array_key_exists('host', $patch) && is_string($patch['host']) ? (NextHop::tryParse($patch['host'])?->stored() ?? (string) $row->hostname) : (string) $row->hostname],
            'username' => ['username', $username],
            'password' => ['password', $password],
            'active' => ['active', array_key_exists('active', $patch) && is_bool($patch['active']) ? (int) $patch['active'] : (int) $row->active],
        ];
        $changes = [];

        foreach ($new as $column => [$member, $value]) {
            $old = $column === 'active' ? (int) $row->active : (string) $row->{$column};

            if ($old !== $value) {
                $changes[$column] = [$member, $old, $value];
            }
        }

        return $changes;
    }

    public function bulkEvent(string $action): AuditEventType
    {
        return $action === 'delete' ? AuditEventType::RelayhostDeletion : AuditEventType::RelayhostUpdate;
    }

    public function bulkResolve(Principal $principal, string $id): ?string
    {
        $resolved = $this->query->resolve($id);

        return $resolved === null ? null : (string) $resolved;
    }

    public function bulkApply(Principal $principal, string $key, string $action, ?string $ifMatch, string $bulkRequestId): ?string
    {
        if ($action === 'delete') {
            $this->delete($principal, (int) $key, $ifMatch, $bulkRequestId);

            return null;
        }

        $this->update($principal, (int) $key, ['active' => $action === 'activate'], $ifMatch, $bulkRequestId);

        return $this->version((int) $key);
    }

    public function bulkAuditTarget(string $id, ?string $key): array
    {
        return RoutingAuditTargets::entryTarget('relayhost', $key ?? $id);
    }

    private function lockedRow(int $id): object
    {
        return DB::table('relayhosts')->where('id', $id)->lockForUpdate()->first() ?? throw new NotFoundHttpException('The relayhost does not exist.');
    }

    /**
     * @return array<string, mixed>
     */
    private function presented(int $id): array
    {
        $row = $this->query->find($id);

        return $row === null ? [] : RelayhostQuery::present($row);
    }

    private static function auditValue(string $member, mixed $value): mixed
    {
        return match ($member) {
            'active' => (int) $value !== 0,
            'username' => $value === '' ? null : $value,
            default => $value,
        };
    }
}
