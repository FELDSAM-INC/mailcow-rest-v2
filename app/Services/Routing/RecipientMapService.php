<?php

namespace App\Services\Routing;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Services\Domains\DomainPresenter;
use App\Support\Http\ETag;
use App\Support\Http\FieldViolations;
use App\Support\Http\ListQuery;
use App\Support\Routing\MapDestination;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * Recipient maps, legacy recipient_map() (feature 009 FR-059–FR-064, research R19): reads,
 * representation and version tag, and the writes. Both destinations are normalised on creation and
 * edit alike (legacy only on creation). Upstream indexes `old_dest` without uniqueness, so
 * creations and edits of the original destination run under the `recipient_maps` named lock; each
 * write is audited in its transaction.
 */
class RecipientMapService implements RoutingBulkHandler
{
    public const SORTS = [
        'id' => 'r.id',
        'originalDestination' => 'r.old_dest',
        'newDestination' => 'r.new_dest',
        'active' => 'r.active',
        'createdAt' => 'r.created',
        'modifiedAt' => 'r.modified',
    ];

    public const WRITABLE = ['originalDestination', 'newDestination', 'active'];

    public function __construct(
        private readonly RoutingAudit $audit,
        private readonly RoutingLock $lock,
    ) {}

    /**
     * @param  array{active: bool|null}  $filters
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}
     */
    public function page(ListQuery $list, array $filters): array
    {
        $query = DB::table('recipient_maps as r')->select('r.*');

        if ($filters['active'] !== null) {
            $query->where('r.active', $filters['active'] ? '<>' : '=', 0);
        }

        $list->applySearch($query, ['r.old_dest', 'r.new_dest']);

        /** @var array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}} */
        return $list->paginate($query, fn (object $row): array => self::present($row), 'r.id');
    }

    public function resolve(string $id): ?int
    {
        if (preg_match('/^[1-9][0-9]{0,9}$/', $id) !== 1 || (int) $id > 2147483647) {
            return null;
        }

        return DB::table('recipient_maps')->where('id', (int) $id)->exists() ? (int) $id : null;
    }

    public function find(int $id): ?object
    {
        return DB::table('recipient_maps')->where('id', $id)->first();
    }

    /**
     * @return array<string, mixed> contract `RecipientMap`
     */
    public static function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'originalDestination' => (string) $row->old_dest,
            'newDestination' => (string) $row->new_dest,
            'active' => (int) $row->active !== 0,
            'createdAt' => DomainPresenter::time($row->created) ?? '1970-01-01T00:00:00Z',
            'modifiedAt' => DomainPresenter::time($row->modified),
            'etag' => self::version($row),
        ];
    }

    public static function version(object $row): string
    {
        return ETag::of(['recipientMap', (int) $row->id, (string) $row->old_dest, (string) $row->new_dest, (int) $row->active]);
    }

    /**
     * @param  array<string, mixed>  $members  validated RecipientMapCreateRequest members
     */
    public function create(Principal $principal, array $members): int
    {
        $violations = new FieldViolations;
        $values = self::values(null, [], $members, $violations);
        $violations->throwIfAny();

        return $this->lock->run(RoutingLock::RECIPIENT_MAPS, function () use ($principal, $values): int {
            $this->assertUnique($values['old_dest'], null);
            $id = (int) DB::table('recipient_maps')->insertGetId($values);

            $this->audit->success(AuditEventType::RecipientMapCreation, $principal, RoutingAudit::target('recipientMap', $id, $values['old_dest']), ['values' => [
                'originalDestination' => $values['old_dest'],
                'newDestination' => $values['new_dest'],
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
        $this->lock->run(RoutingLock::RECIPIENT_MAPS, function () use ($principal, $id, $patch, $ifMatch, $bulkRequestId): void {
            $row = DB::table('recipient_maps')->where('id', $id)->lockForUpdate()->first() ?? throw new NotFoundHttpException('The recipient map does not exist.');

            if (ETag::preconditionFails($ifMatch, self::version($row))) {
                throw new PreconditionFailedHttpException('The recipient map was changed since it was read.');
            }

            $violations = new FieldViolations;
            $values = self::values($row, self::present($row), $patch, $violations);
            $violations->throwIfAny();

            if (strtolower($values['old_dest']) !== strtolower((string) $row->old_dest)) {
                $this->assertUnique($values['old_dest'], $id);
            }

            $changes = [];
            $columns = [];

            foreach (['old_dest' => 'originalDestination', 'new_dest' => 'newDestination', 'active' => 'active'] as $column => $member) {
                $old = $column === 'active' ? (int) $row->active : (string) $row->{$column};

                if ($old !== $values[$column]) {
                    $columns[$column] = $values[$column];
                    $changes[$member] = $column === 'active' ? ['old' => $old !== 0, 'new' => $values[$column] !== 0] : ['old' => $old, 'new' => $values[$column]];
                }
            }

            if ($columns !== []) {
                DB::table('recipient_maps')->where('id', $id)->update($columns);
            }

            $this->audit->success(AuditEventType::RecipientMapUpdate, $principal, RoutingAudit::target('recipientMap', $id, $values['old_dest']), ['changes' => (object) $changes], $bulkRequestId);
        });
    }

    public function delete(Principal $principal, int $id, ?string $ifMatch, ?string $bulkRequestId = null): void
    {
        DB::transaction(function () use ($principal, $id, $ifMatch, $bulkRequestId): void {
            $row = DB::table('recipient_maps')->where('id', $id)->lockForUpdate()->first() ?? throw new NotFoundHttpException('The recipient map does not exist.');

            if (ETag::preconditionFails($ifMatch, self::version($row))) {
                throw new PreconditionFailedHttpException('The recipient map was changed since it was read.');
            }

            DB::table('recipient_maps')->where('id', $id)->delete();
            $this->audit->success(AuditEventType::RecipientMapDeletion, $principal, RoutingAudit::target('recipientMap', $id, (string) $row->old_dest), ['originalDestination' => (string) $row->old_dest], $bulkRequestId);
        });
    }

    /**
     * The stored values a creation (`$row` null) or a patch results in, with violations.
     *
     * @param  array<string, mixed>  $presented
     * @param  array<string, mixed>  $input
     * @return array{old_dest: string, new_dest: string, active: int}
     */
    public static function values(?object $row, array $presented, array $input, FieldViolations $violations): array
    {
        if ($row !== null) {
            PatchMembers::check($input, self::WRITABLE, array_intersect_key($presented, array_flip(['id', 'createdAt', 'modifiedAt', 'etag'])), $violations);
        }

        $values = [
            'old_dest' => (string) ($row->old_dest ?? ''),
            'new_dest' => (string) ($row->new_dest ?? ''),
            'active' => $row === null ? 1 : (int) $row->active,
        ];

        foreach (['originalDestination' => 'old_dest', 'newDestination' => 'new_dest'] as $member => $column) {
            if (! array_key_exists($member, $input)) {
                continue;
            }

            $violation = MapDestination::recipientMapViolation($input[$member]);

            if ($violation !== null) {
                $violations->add($member, $violation['code'], $violation['params'], $violation['message']);
            } else {
                $values[$column] = (string) MapDestination::recipientMap((string) $input[$member]);
            }
        }

        if (array_key_exists('active', $input) && is_bool($input['active'])) {
            $values['active'] = (int) $input['active'];
        }

        return $values;
    }

    public function bulkEvent(string $action): AuditEventType
    {
        return $action === 'delete' ? AuditEventType::RecipientMapDeletion : AuditEventType::RecipientMapUpdate;
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
        return RoutingAuditTargets::entryTarget('recipientMap', $key ?? $id);
    }

    private function assertUnique(string $originalDestination, ?int $except): void
    {
        $query = DB::table('recipient_maps')->where('old_dest', $originalDestination);

        if ($except !== null) {
            $query->where('id', '<>', $except);
        }

        $existing = $query->orderBy('id')->lockForUpdate()->value('id');

        if ($existing !== null) {
            throw RoutingConflict::entryExists('originalDestination', (int) $existing, 'A recipient map for this original destination already exists.');
        }
    }
}
