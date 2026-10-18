<?php

namespace App\Services\Routing;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Services\Domains\DomainPresenter;
use App\Support\Http\ETag;
use App\Support\Http\FieldViolations;
use App\Support\Http\ListQuery;
use App\Support\Routing\MapDestination;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * TLS policy maps, legacy tls_policy_maps() (feature 009 FR-065–FR-073, research R19): reads,
 * representation and version tag, and the writes, each in one transaction with its audit record.
 * Destinations, policies and parameters are normalised on creation and edit alike (legacy only on
 * creation, and it never checked the policy). `dest` has an upstream unique index: the service
 * checks first, to name the existing entry, and also answers a duplicate-key error of a concurrent
 * insert with `409 routing-entry-exists`.
 */
class TlsPolicyMapService implements RoutingBulkHandler
{
    public const SORTS = [
        'id' => 't.id',
        'destination' => 't.dest',
        'policy' => 'policy_text',
        'active' => 't.active',
        'createdAt' => 't.created',
        'modifiedAt' => 't.modified',
    ];

    public const WRITABLE = ['destination', 'policy', 'parameters', 'active'];

    public function __construct(private readonly RoutingAudit $audit) {}

    /**
     * @param  array{policy: list<string>, active: bool|null}  $filters
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}
     */
    public function page(ListQuery $list, array $filters): array
    {
        $query = DB::table('tls_policy_override as t')->select('t.*')->selectRaw('CAST(`t`.`policy` AS CHAR) AS `policy_text`');

        if ($filters['policy'] !== []) {
            $query->whereIn('t.policy', $filters['policy']);
        }

        if ($filters['active'] !== null) {
            $query->where('t.active', $filters['active'] ? '<>' : '=', 0);
        }

        $list->applySearch($query, ['t.dest', 't.parameters']);

        /** @var array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}} */
        return $list->paginate($query, fn (object $row): array => self::present($row), 't.id');
    }

    public function resolve(string $id): ?int
    {
        if (preg_match('/^[1-9][0-9]{0,9}$/', $id) !== 1 || (int) $id > 2147483647) {
            return null;
        }

        return DB::table('tls_policy_override')->where('id', (int) $id)->exists() ? (int) $id : null;
    }

    public function find(int $id): ?object
    {
        return DB::table('tls_policy_override')->where('id', $id)->first();
    }

    /**
     * @return array<string, mixed> contract `TlsPolicyMap`
     */
    public static function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'destination' => (string) $row->dest,
            'policy' => (string) $row->policy,
            'parameters' => (string) ($row->parameters ?? ''),
            'active' => (int) $row->active !== 0,
            'createdAt' => DomainPresenter::time($row->created) ?? '1970-01-01T00:00:00Z',
            'modifiedAt' => DomainPresenter::time($row->modified),
            'etag' => self::version($row),
        ];
    }

    public static function version(object $row): string
    {
        return ETag::of(['tlsPolicyMap', (int) $row->id, (string) $row->dest, (string) $row->policy, (string) ($row->parameters ?? ''), (int) $row->active]);
    }

    /**
     * @param  array<string, mixed>  $members  validated TlsPolicyMapCreateRequest members
     */
    public function create(Principal $principal, array $members): int
    {
        $violations = new FieldViolations;
        $values = self::values(null, [], $members, $violations);
        $violations->throwIfAny();

        return DB::transaction(function () use ($principal, $values): int {
            $this->assertUnique($values['dest'], null);
            $id = $this->write(fn (): int => (int) DB::table('tls_policy_override')->insertGetId($values), $values['dest']);

            $this->audit->success(AuditEventType::TlsPolicyMapCreation, $principal, RoutingAudit::target('tlsPolicyMap', $id, $values['dest']), ['values' => [
                'destination' => $values['dest'],
                'policy' => $values['policy'],
                'parameters' => $values['parameters'],
                'active' => $values['active'] !== 0,
            ]]);

            return $id;
        });
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function update(Principal $principal, int $id, array $patch, ?string $ifMatch, ?string $bulkRequestId = null): void
    {
        DB::transaction(function () use ($principal, $id, $patch, $ifMatch, $bulkRequestId): void {
            $row = DB::table('tls_policy_override')->where('id', $id)->lockForUpdate()->first() ?? throw new NotFoundHttpException('The TLS policy map does not exist.');

            if (ETag::preconditionFails($ifMatch, self::version($row))) {
                throw new PreconditionFailedHttpException('The TLS policy map was changed since it was read.');
            }

            $violations = new FieldViolations;
            $values = self::values($row, self::present($row), $patch, $violations);
            $violations->throwIfAny();

            if (strtolower($values['dest']) !== strtolower((string) $row->dest)) {
                $this->assertUnique($values['dest'], $id);
            }

            $changes = [];
            $columns = [];

            foreach (['dest' => 'destination', 'policy' => 'policy', 'parameters' => 'parameters', 'active' => 'active'] as $column => $member) {
                $old = $column === 'active' ? (int) $row->active : (string) ($row->{$column} ?? '');

                if ($old !== $values[$column]) {
                    $columns[$column] = $values[$column];
                    $changes[$member] = $column === 'active' ? ['old' => $old !== 0, 'new' => $values[$column] !== 0] : ['old' => $old, 'new' => $values[$column]];
                }
            }

            if ($columns !== []) {
                $this->write(fn (): int => DB::table('tls_policy_override')->where('id', $id)->update($columns), $values['dest']);
            }

            $this->audit->success(AuditEventType::TlsPolicyMapUpdate, $principal, RoutingAudit::target('tlsPolicyMap', $id, $values['dest']), ['changes' => (object) $changes], $bulkRequestId);
        });
    }

    public function delete(Principal $principal, int $id, ?string $ifMatch, ?string $bulkRequestId = null): void
    {
        DB::transaction(function () use ($principal, $id, $ifMatch, $bulkRequestId): void {
            $row = DB::table('tls_policy_override')->where('id', $id)->lockForUpdate()->first() ?? throw new NotFoundHttpException('The TLS policy map does not exist.');

            if (ETag::preconditionFails($ifMatch, self::version($row))) {
                throw new PreconditionFailedHttpException('The TLS policy map was changed since it was read.');
            }

            DB::table('tls_policy_override')->where('id', $id)->delete();
            $this->audit->success(AuditEventType::TlsPolicyMapDeletion, $principal, RoutingAudit::target('tlsPolicyMap', $id, (string) $row->dest), ['destination' => (string) $row->dest], $bulkRequestId);
        });
    }

    /**
     * The stored values a creation (`$row` null) or a patch results in, with violations for every
     * invalid member, and for read-only and unknown members of a patch.
     *
     * @param  array<string, mixed>  $presented
     * @param  array<string, mixed>  $input
     * @return array{dest: string, policy: string, parameters: string, active: int}
     */
    public static function values(?object $row, array $presented, array $input, FieldViolations $violations): array
    {
        if ($row !== null) {
            PatchMembers::check($input, self::WRITABLE, array_intersect_key($presented, array_flip(['id', 'createdAt', 'modifiedAt', 'etag'])), $violations);
        }

        $values = [
            'dest' => (string) ($row->dest ?? ''),
            'policy' => (string) ($row->policy ?? ''),
            'parameters' => (string) ($row->parameters ?? ''),
            'active' => $row === null ? 1 : (int) $row->active,
        ];

        foreach ([
            'destination' => ['dest', MapDestination::tlsPolicyViolation(...), MapDestination::tlsPolicy(...)],
            'policy' => ['policy', MapDestination::tlsPolicyNameViolation(...), fn (string $v): string => strtolower(trim($v))],
            'parameters' => ['parameters', MapDestination::tlsParametersViolation(...), fn (string $v): string => (string) MapDestination::tlsParameters($v)],
        ] as $member => [$column, $check, $normalize]) {
            if (! array_key_exists($member, $input)) {
                continue;
            }

            $violation = $check($input[$member]);

            if ($violation !== null) {
                $violations->add($member, $violation['code'], $violation['params'], $violation['message']);
            } else {
                $values[$column] = (string) $normalize((string) $input[$member]);
            }
        }

        if (array_key_exists('active', $input) && is_bool($input['active'])) {
            $values['active'] = (int) $input['active'];
        }

        return $values;
    }

    public function bulkEvent(string $action): AuditEventType
    {
        return $action === 'delete' ? AuditEventType::TlsPolicyMapDeletion : AuditEventType::TlsPolicyMapUpdate;
    }

    public function bulkResolve(Principal $principal, string $id): ?string
    {
        $resolved = $this->resolve($id);

        return $resolved === null ? null : (string) $resolved;
    }

    public function bulkApply(Principal $principal, string $key, string $action, ?string $ifMatch, string $bulkRequestId): ?string
    {
        if ($action === 'delete') {
            $this->delete($principal, (int) $key, $ifMatch, $bulkRequestId);

            return null;
        }

        $this->update($principal, (int) $key, ['active' => $action === 'activate'], $ifMatch, $bulkRequestId);
        $row = $this->find((int) $key);

        return $row === null ? null : self::version($row);
    }

    public function bulkAuditTarget(string $id, ?string $key): array
    {
        return RoutingAuditTargets::entryTarget('tlsPolicyMap', $key ?? $id);
    }

    /** The ID of another entry with the destination (letter case ignored), or null. */
    protected function existing(string $destination, ?int $except): ?int
    {
        $query = DB::table('tls_policy_override')->where('dest', $destination);

        if ($except !== null) {
            $query->where('id', '<>', $except);
        }

        $id = $query->lockForUpdate()->value('id');

        return $id === null ? null : (int) $id;
    }

    private function assertUnique(string $destination, ?int $except): void
    {
        $existing = $this->existing($destination, $except);

        if ($existing !== null) {
            throw RoutingConflict::entryExists('destination', $existing, 'A TLS policy map for this destination already exists.');
        }
    }

    /**
     * Runs a write, answering a duplicate-key error (MariaDB 1062) with `409`.
     *
     * @param  callable(): int  $write
     */
    private function write(callable $write, string $destination): int
    {
        try {
            return $write();
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }

            $existing = DB::table('tls_policy_override')->where('dest', $destination)->value('id');

            throw RoutingConflict::entryExists('destination', (int) ($existing ?? 0), 'A TLS policy map for this destination already exists.');
        }
    }
}
