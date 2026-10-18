<?php

namespace App\Services\Routing;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Support\Http\ETag;
use App\Support\Http\FieldViolations;
use App\Support\Routing\NextHop;
use App\Support\Routing\TransportDestination;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * Transport writes, legacy transport('add' / 'edit' / 'delete') (feature 009 FR-034–FR-040,
 * FR-083, FR-084, research R6–R9). Creations and edits run under the `transports` named lock in one
 * transaction with their audit record:
 *
 *  - destinations are unique among all transports, compared without regard to letter case (the
 *    column collation), checked with a locking read so that the lock's holder sees the latest data;
 *  - `host` and `[host]…` never coexist as next hops (letter case ignored);
 *  - credentials belong to the next hop (letter case ignored, as Postfix's lookup compares them): a
 *    creation without credentials inherits them, one with another username is refused, one with a
 *    password replaces every password of the next hop; an edit that sends credentials applies them
 *    to the whole resulting next hop, and a move without credentials adopts the target's. Legacy
 *    instead wiped the shared password and overwrote the target's credentials.
 *
 * The other transports whose stored credentials changed are returned (for `meta`) and audited.
 */
class TransportService implements RoutingBulkHandler
{
    public const WRITABLE = ['destination', 'nextHop', 'mxBased', 'active', 'username', 'password'];

    public function __construct(
        private readonly TransportQuery $query,
        private readonly RoutingAudit $audit,
        private readonly RoutingLock $lock,
    ) {}

    /**
     * @param  array<string, mixed>  $members  validated TransportCreateRequest members
     * @return array{id: int, affected: list<int>}
     */
    public function create(Principal $principal, array $members): array
    {
        $mxBased = (bool) ($members['mxBased'] ?? false);
        $destination = TransportDestination::normalize((string) $members['destination'], $mxBased) ?? (string) $members['destination'];
        $hop = NextHop::parse((string) $members['nextHop']);
        $active = (bool) ($members['active'] ?? false);
        $sentUsername = array_key_exists('username', $members) ? trim((string) $members['username']) : null;
        $sentPassword = array_key_exists('password', $members) ? trim((string) $members['password']) : null;

        return $this->lock->run(RoutingLock::TRANSPORTS, function () use ($principal, $destination, $hop, $mxBased, $active, $sentUsername, $sentPassword): array {
            $this->assertUniqueDestination($destination, null);
            $this->assertNoInterference($hop, null);

            $others = $this->sameNextHop($hop->stored(), null);
            $username = $sentUsername ?? '';
            $password = $sentPassword ?? '';

            if ($others->isNotEmpty()) {
                $source = self::credentialSource($others);

                if ($sentUsername !== null && $others->contains(fn (object $row): bool => (string) $row->username !== $sentUsername)) {
                    throw RoutingConflict::credentialsDiffer((string) $source->nexthop, $others->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all());
                }

                $username = (string) $source->username;
                $password = $sentPassword ?? (string) $source->password;
            }

            $violations = new FieldViolations;
            RelayhostService::credentialViolations($username, $password, $violations);
            $violations->throwIfAny();

            $id = (int) DB::table('transports')->insertGetId([
                'nexthop' => $hop->stored(),
                'destination' => $destination,
                'is_mx_based' => (int) $mxBased,
                'username' => $username,
                'password' => $password,
                'active' => (int) $active,
            ]);

            $affected = $sentPassword === null ? [] : $this->propagate($others, $username, $password);

            $this->audit->success(AuditEventType::TransportCreation, $principal, RoutingAudit::target('transport', $id, $destination), [
                'values' => [
                    'destination' => $destination,
                    'nextHop' => $hop->stored(),
                    'mxBased' => $mxBased,
                    'active' => $active,
                    'username' => $username === '' ? null : $username,
                    'password' => $password === '' ? null : 'set',
                ],
                'affectedTransportIds' => $affected,
            ]);

            return ['id' => $id, 'affected' => $affected];
        });
    }

    /**
     * @param  array<string, mixed>  $patch  the merge patch as sent (types validated)
     * @return list<int> the other transports whose credentials changed
     */
    public function update(Principal $principal, int $id, array $patch, ?string $ifMatch, ?string $bulkRequestId = null): array
    {
        return $this->lock->run(RoutingLock::TRANSPORTS, function () use ($principal, $id, $patch, $ifMatch, $bulkRequestId): array {
            $row = DB::table('transports')->where('id', $id)->lockForUpdate()->first() ?? throw new NotFoundHttpException('The transport does not exist.');

            if (ETag::preconditionFails($ifMatch, TransportQuery::version($row))) {
                throw new PreconditionFailedHttpException('The transport was changed since it was read.');
            }

            $violations = new FieldViolations;
            $presented = TransportQuery::present($this->query->find($id) ?? $row);
            $plan = self::plan($row, $presented, $patch, $violations);
            $violations->throwIfAny();

            if ($plan['destinationChecked']) {
                $this->assertUniqueDestination($plan['values']['destination'], $id);
            }

            $hop = NextHop::tryParse($plan['values']['nexthop']);

            if ($hop !== null && $plan['nextHopChanged']) {
                $this->assertNoInterference($hop, $id);
            }

            $others = $this->sameNextHop($plan['values']['nexthop'], $id);
            $credentialsSent = array_key_exists('username', $patch) || array_key_exists('password', $patch);
            $username = (string) $row->username;
            $password = (string) $row->password;

            if ($plan['nextHopChanged'] && $others->isNotEmpty()) {
                $source = self::credentialSource($others);
                $username = (string) $source->username;
                $password = (string) $source->password;
            }

            if (array_key_exists('username', $patch) && is_string($patch['username'])) {
                $username = trim($patch['username']);
            }

            if (array_key_exists('password', $patch) && is_string($patch['password'])) {
                $password = trim($patch['password']);
            } elseif ($username === '') {
                $password = '';
            }

            if ($credentialsSent) {
                RelayhostService::credentialViolations($username, $password, $violations);
                $violations->throwIfAny();
            }

            $values = $plan['values'] + ['username' => $username, 'password' => $password];
            $columns = [];
            $changes = [];

            foreach (['destination' => 'destination', 'nexthop' => 'nextHop', 'is_mx_based' => 'mxBased', 'active' => 'active', 'username' => 'username', 'password' => 'password'] as $column => $member) {
                $old = in_array($column, ['is_mx_based', 'active'], true) ? (int) $row->{$column} : (string) $row->{$column};

                if ($old === $values[$column]) {
                    continue;
                }

                $columns[$column] = $values[$column];
                $changes[$member] = match ($member) {
                    'password' => RoutingAudit::passwordChange((string) $old, (string) $values[$column]),
                    'mxBased', 'active' => ['old' => $old !== 0, 'new' => $values[$column] !== 0],
                    'username' => ['old' => $old === '' ? null : $old, 'new' => $values[$column] === '' ? null : $values[$column]],
                    default => ['old' => $old, 'new' => $values[$column]],
                };
            }

            if ($columns !== []) {
                DB::table('transports')->where('id', $id)->update($columns);
            }

            $affected = $credentialsSent ? $this->propagate($others, $username, $password) : [];

            $this->audit->success(
                AuditEventType::TransportUpdate,
                $principal,
                RoutingAudit::target('transport', $id, (string) $values['destination']),
                ['changes' => (object) $changes, 'affectedTransportIds' => $affected],
                $bulkRequestId,
            );

            return $affected;
        });
    }

    public function delete(Principal $principal, int $id, ?string $ifMatch, ?string $bulkRequestId = null): void
    {
        DB::transaction(function () use ($principal, $id, $ifMatch, $bulkRequestId): void {
            $row = DB::table('transports')->where('id', $id)->lockForUpdate()->first() ?? throw new NotFoundHttpException('The transport does not exist.');

            if (ETag::preconditionFails($ifMatch, TransportQuery::version($row))) {
                throw new PreconditionFailedHttpException('The transport was changed since it was read.');
            }

            DB::table('transports')->where('id', $id)->delete();

            $this->audit->success(AuditEventType::TransportDeletion, $principal, RoutingAudit::target('transport', $id, (string) $row->destination), [
                'destination' => (string) $row->destination,
                'nextHop' => (string) $row->nexthop,
            ], $bulkRequestId);
        });
    }

    /**
     * The non-credential values a patch results in (`destination`, `nexthop`, `is_mx_based`,
     * `active`), whether the destination needs a uniqueness check and whether the next hop changes;
     * violations for read-only and unknown members and for a destination that is not valid for the
     * resulting kind.
     *
     * @param  array<string, mixed>  $presented
     * @param  array<string, mixed>  $patch
     * @return array{values: array{destination: string, nexthop: string, is_mx_based: int, active: int}, destinationChecked: bool, nextHopChanged: bool}
     */
    public static function plan(object $row, array $presented, array $patch, FieldViolations $violations): array
    {
        PatchMembers::check($patch, self::WRITABLE, array_intersect_key($presented, array_flip(['id', 'nextHopSharedWith', 'passwordSet', 'etag'])), $violations);

        $mxBased = array_key_exists('mxBased', $patch) && is_bool($patch['mxBased']) ? $patch['mxBased'] : (int) $row->is_mx_based !== 0;
        $destination = (string) $row->destination;
        $destinationChecked = array_key_exists('destination', $patch) || (array_key_exists('mxBased', $patch) && $mxBased !== ((int) $row->is_mx_based !== 0));

        if ($destinationChecked) {
            $raw = array_key_exists('destination', $patch) ? $patch['destination'] : $destination;
            $violation = TransportDestination::violation($raw, $mxBased);

            if ($violation !== null) {
                $violations->add('destination', $violation['code'], $violation['params'], $violation['message']);
            } else {
                $destination = TransportDestination::normalize((string) $raw, $mxBased) ?? $destination;
            }
        }

        $nexthop = (string) $row->nexthop;

        if (array_key_exists('nextHop', $patch) && is_string($patch['nextHop'])) {
            $nexthop = NextHop::tryParse($patch['nextHop'])?->stored() ?? $nexthop;
        }

        return [
            'values' => [
                'destination' => $destination,
                'nexthop' => $nexthop,
                'is_mx_based' => (int) $mxBased,
                'active' => array_key_exists('active', $patch) && is_bool($patch['active']) ? (int) $patch['active'] : (int) $row->active,
            ],
            'destinationChecked' => $destinationChecked,
            'nextHopChanged' => strtolower($nexthop) !== strtolower((string) $row->nexthop),
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{id: int, destination: string, nextHop: string, etag: string}>
     */
    public function affectedReferences(array $ids): array
    {
        return $this->query->references($ids);
    }

    public function bulkEvent(string $action): AuditEventType
    {
        return $action === 'delete' ? AuditEventType::TransportDeletion : AuditEventType::TransportUpdate;
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
        $row = DB::table('transports')->where('id', (int) $key)->first();

        return $row === null ? null : TransportQuery::version($row);
    }

    public function bulkAuditTarget(string $id, ?string $key): array
    {
        return RoutingAuditTargets::entryTarget('transport', $key ?? $id);
    }

    /**
     * `409 routing-entry-exists` when another transport has the destination (letter case ignored).
     */
    private function assertUniqueDestination(string $destination, ?int $except): void
    {
        $query = DB::table('transports')->where('destination', $destination);

        if ($except !== null) {
            $query->where('id', '<>', $except);
        }

        $existing = $query->orderBy('id')->lockForUpdate()->value('id');

        if ($existing !== null) {
            throw RoutingConflict::entryExists('destination', (int) $existing, 'A transport for this destination already exists.');
        }
    }

    /**
     * `409 next-hop-interferes` when `host` and `[host]…` would both be next hops (FR-037).
     */
    private function assertNoInterference(NextHop $hop, ?int $except): void
    {
        $candidates = DB::table('transports')->select(['id', 'nexthop'])->orderBy('id');

        if ($except !== null) {
            $candidates->where('id', '<>', $except);
        }

        if ($hop->bracketed()) {
            $rows = $candidates->where('nexthop', $hop->cleanHost())->lockForUpdate()->get();
        } else {
            $rows = $candidates->where('nexthop', 'like', '[%')->lockForUpdate()->get()
                ->filter(fn (object $row): bool => preg_match('/\[(.+)\].*/', (string) $row->nexthop, $m) === 1 && strtolower($m[1]) === strtolower($hop->stored()));
        }

        if ($rows->isNotEmpty()) {
            throw RoutingConflict::interferes((string) $rows->first()->nexthop, $hop->stored(), $rows->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all());
        }
    }

    /**
     * The other transports with the next hop (letter case ignored), locked.
     *
     * @return Collection<int, \stdClass>
     */
    private function sameNextHop(string $nexthop, ?int $except): Collection
    {
        $query = DB::table('transports')->where('nexthop', $nexthop)->orderBy('id');

        if ($except !== null) {
            $query->where('id', '<>', $except);
        }

        return $query->lockForUpdate()->get();
    }

    /**
     * The row whose credentials a next hop has: the first active row with a username, as the
     * mail server's lookup most likely returns it, else the first row.
     *
     * @param  Collection<int, \stdClass>  $rows
     */
    private static function credentialSource(Collection $rows): object
    {
        return $rows->first(fn (object $row): bool => (int) $row->active !== 0 && (string) $row->username !== '') ?? $rows->first();
    }

    /**
     * Writes the credentials to every given transport whose stored ones differ (compared exactly)
     * and returns their IDs.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @return list<int>
     */
    private function propagate(Collection $rows, string $username, string $password): array
    {
        $ids = $rows->filter(fn (object $row): bool => (string) $row->username !== $username || (string) $row->password !== $password)
            ->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();

        if ($ids !== []) {
            DB::table('transports')->whereIn('id', $ids)->update(['username' => $username, 'password' => $password]);
        }

        return $ids;
    }
}
