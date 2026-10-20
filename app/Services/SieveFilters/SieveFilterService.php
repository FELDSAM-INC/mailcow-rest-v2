<?php

namespace App\Services\SieveFilters;

use App\Audit\AuditEventType;
use App\Auth\PermissionFlag;
use App\Auth\Principal;
use App\Auth\Role;
use App\Casts\MailcowDateTime;
use App\Services\Audit\AuditLogger;
use App\Services\Mailboxes\MailboxAddress;
use App\Services\Mailboxes\MailboxScope;
use App\Services\SelfService\PermissionGate;
use App\Support\Database\NamedLock;
use App\Support\Http\ETag;
use App\Support\Http\ListQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * The Sieve filters an administrator keeps for a mailbox (feature 005 FR-047 to FR-051, research
 * R13), stored in mailcow's `sieve_filters` exactly as legacy mailbox('add' | 'edit' | 'delete',
 * 'filter') stores them; Dovecot reads them through the `sieve_before` and `sieve_after` views.
 *
 * Activating a filter deactivates the mailbox's other active filters of its kind in the same
 * transaction, under the named lock `restv2_sieve_<sha1(mailbox)>`, so concurrent activations leave
 * at most one active filter per kind (a rule legacy enforces only in code). Changes need the
 * `filters` flag (administrators always have it, domain administrators their own flag); mailbox
 * users only read. The request validates the members (legacy's parser, lengths).
 */
class SieveFilterService
{
    /** Contract sort value => column. */
    public const SORT_COLUMNS = [
        'id' => 'sieve_filters.id',
        'description' => 'sieve_filters.script_desc',
        'mailbox' => 'sieve_filters.username',
        'kind' => 'sieve_filters.filter_type',
        'active' => 'is_active',
        'createdAt' => 'sieve_filters.created',
        'modifiedAt' => 'sieve_filters.modified',
    ];

    public const KINDS = ['prefilter', 'postfilter'];

    public function __construct(
        private readonly NamedLock $locks,
        private readonly PermissionGate $gate,
        private readonly AuditLogger $audit,
        private readonly MailboxScope $scope,
    ) {}

    public static function lockName(string $mailbox): string
    {
        return 'restv2_sieve_'.sha1($mailbox);
    }

    /**
     * Refuses changes of callers who may not change filters: mailbox users (`403 forbidden`) and
     * domain administrators without `filters` (`403 permission-flag-required`).
     */
    public function assertMayChange(Principal $principal): void
    {
        if ($principal->role === Role::MailboxUser) {
            throw new AccessDeniedHttpException('Mailbox users cannot change Sieve filters.');
        }

        $this->gate->requireChange($principal, PermissionFlag::Filters);
    }

    /**
     * The filters of every mailbox in the caller's scope (administrators and domain administrators).
     *
     * @param  array{mailbox?: string|null, kind?: string|null, active?: bool|null, id?: int|null}  $filters
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}
     */
    public function listInScope(Principal $principal, ListQuery $query, array $filters): array
    {
        $builder = $this->base()->join('mailbox', 'mailbox.username', '=', 'sieve_filters.username');
        $this->scope->restrict($builder, $principal, 'mailbox.domain');

        if (isset($filters['mailbox'])) {
            $address = MailboxAddress::normalize($filters['mailbox']);
            $address === null ? $builder->whereRaw('1 = 0') : $builder->where('sieve_filters.username', $address->address);
        }

        if (isset($filters['id'])) {
            $builder->where('sieve_filters.id', $filters['id']);
        }

        $this->filter($builder, $filters);
        $query->applySearch($builder, ['sieve_filters.script_desc', 'sieve_filters.username']);

        return $this->page($builder, $query);
    }

    /**
     * The filters of one mailbox.
     *
     * @param  array{kind?: string|null, active?: bool|null}  $filters
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}
     */
    public function listOfMailbox(string $mailbox, ListQuery $query, array $filters): array
    {
        $builder = $this->base()->where('sieve_filters.username', $mailbox);
        $this->filter($builder, $filters);
        $query->applySearch($builder, ['sieve_filters.script_desc']);

        return $this->page($builder, $query);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $mailbox, int $id): ?array
    {
        $row = $this->row($mailbox, $id);

        return $row === null ? null : $this->present($row);
    }

    /**
     * @param  array{description: string, kind: string, script: string, active?: bool}  $input
     * @return array<string, mixed> contract `SieveFilter`
     */
    public function create(Principal $principal, string $mailbox, array $input): array
    {
        $this->assertMayChange($principal);
        $active = $input['active'] ?? true;

        return $this->locks->run([self::lockName($mailbox)], fn (): array => DB::transaction(function () use ($principal, $mailbox, $input, $active): array {
            $deactivated = $active ? $this->deactivateOthers($mailbox, $input['kind'], null) : [];
            $id = (int) DB::table('sieve_filters')->insertGetId([
                'username' => $mailbox,
                'script_data' => $input['script'],
                'script_desc' => $input['description'],
                'script_name' => $active ? 'active' : 'inactive',
                'filter_type' => $input['kind'],
            ]);

            $this->audit->record(
                AuditEventType::SieveFilterCreation,
                true,
                $principal,
                ['type' => 'sieveFilter', 'id' => $mailbox, 'name' => $input['description']],
                null,
                null,
                [
                    'sieveFilterId' => $id,
                    'values' => ['description' => $input['description'], 'kind' => $input['kind'], 'active' => $active, 'script' => ['size' => strlen($input['script'])]],
                    'deactivatedIds' => $deactivated,
                ],
            );

            return $this->present($this->row($mailbox, $id) ?? throw new NotFoundHttpException('The Sieve filter does not exist.'));
        }));
    }

    /**
     * Changes the members sent (JSON Merge Patch); the mailbox never changes.
     *
     * @param  array{description?: string, kind?: string, script?: string, active?: bool}  $patch
     * @return array<string, mixed> contract `SieveFilter`
     */
    public function update(Principal $principal, string $mailbox, int $id, array $patch, ?string $ifMatch): array
    {
        $this->assertMayChange($principal);

        return $this->locks->run([self::lockName($mailbox)], fn (): array => DB::transaction(function () use ($principal, $mailbox, $id, $patch, $ifMatch): array {
            $row = $this->row($mailbox, $id, true) ?? throw new NotFoundHttpException('The Sieve filter does not exist.');
            $current = $this->present($row);

            if (ETag::preconditionFails($ifMatch, $current['etag'])) {
                throw new PreconditionFailedHttpException('The Sieve filter has changed.');
            }

            $new = [
                'description' => $patch['description'] ?? $current['description'],
                'kind' => $patch['kind'] ?? $current['kind'],
                'script' => $patch['script'] ?? $current['script'],
                'active' => $patch['active'] ?? $current['active'],
            ];

            // legacy deactivates the kind's other filters whenever the edited filter is active,
            // so activating it or moving an active filter to the other kind leaves one active.
            $deactivated = $new['active'] ? $this->deactivateOthers($mailbox, $new['kind'], $id) : [];

            DB::table('sieve_filters')->where('id', $id)->update([
                'script_desc' => $new['description'],
                'script_data' => $new['script'],
                'script_name' => $new['active'] ? 'active' : 'inactive',
                'filter_type' => $new['kind'],
            ]);

            $changes = [];

            foreach (['description', 'kind', 'active'] as $member) {
                if ($new[$member] !== $current[$member]) {
                    $changes[$member] = ['old' => $current[$member], 'new' => $new[$member]];
                }
            }

            if ($new['script'] !== $current['script']) {
                $changes['script'] = ['changed' => true, 'oldSize' => strlen($current['script']), 'newSize' => strlen($new['script'])];
            }

            $this->audit->record(
                AuditEventType::SieveFilterUpdate,
                true,
                $principal,
                ['type' => 'sieveFilter', 'id' => $mailbox, 'name' => $new['description']],
                null,
                null,
                ['sieveFilterId' => $id, 'changes' => $changes, 'deactivatedIds' => $deactivated],
            );

            return $this->present($this->row($mailbox, $id) ?? throw new NotFoundHttpException('The Sieve filter does not exist.'));
        }));
    }

    public function delete(Principal $principal, string $mailbox, int $id, ?string $ifMatch): void
    {
        $this->assertMayChange($principal);

        DB::transaction(function () use ($principal, $mailbox, $id, $ifMatch): void {
            $row = $this->row($mailbox, $id, true) ?? throw new NotFoundHttpException('The Sieve filter does not exist.');
            $current = $this->present($row);

            if (ETag::preconditionFails($ifMatch, $current['etag'])) {
                throw new PreconditionFailedHttpException('The Sieve filter has changed.');
            }

            DB::table('sieve_filters')->where('id', $id)->delete();

            $this->audit->record(
                AuditEventType::SieveFilterDeletion,
                true,
                $principal,
                ['type' => 'sieveFilter', 'id' => $mailbox, 'name' => $current['description']],
                null,
                null,
                [
                    'sieveFilterId' => $id,
                    'removed' => ['description' => $current['description'], 'kind' => $current['kind'], 'active' => $current['active'], 'script' => ['size' => strlen($current['script'])]],
                ],
            );
        });
    }

    /**
     * @param  array<string, mixed>  $filter  contract `SieveFilter`
     */
    public static function etag(array $filter): string
    {
        return ETag::of(['sieveFilter', $filter['id'], $filter['description'], $filter['kind'], sha1((string) $filter['script']), $filter['active'], $filter['modifiedAt']]);
    }

    /**
     * Deactivates the mailbox's other active filters of the kind; returns their IDs.
     *
     * @return list<int>
     */
    private function deactivateOthers(string $mailbox, string $kind, ?int $except): array
    {
        $query = DB::table('sieve_filters')->where('username', $mailbox)->where('filter_type', $kind)->where('script_name', 'active');

        if ($except !== null) {
            $query->where('id', '<>', $except);
        }

        $ids = array_map('intval', $query->orderBy('id')->lockForUpdate()->pluck('id')->all());

        if ($ids !== []) {
            DB::table('sieve_filters')->whereIn('id', $ids)->update(['script_name' => 'inactive']);
        }

        return $ids;
    }

    private function base(): Builder
    {
        return DB::table('sieve_filters')
            ->select(['sieve_filters.id', 'sieve_filters.username', 'sieve_filters.script_desc', 'sieve_filters.script_name', 'sieve_filters.script_data', 'sieve_filters.filter_type', 'sieve_filters.created', 'sieve_filters.modified'])
            ->selectRaw("(`sieve_filters`.`script_name` = 'active') AS `is_active`");
    }

    /**
     * @param  array{kind?: string|null, active?: bool|null}  $filters
     */
    private function filter(Builder $builder, array $filters): void
    {
        if (isset($filters['kind'])) {
            $builder->where('sieve_filters.filter_type', $filters['kind']);
        }

        if (isset($filters['active'])) {
            $filters['active']
                ? $builder->where('sieve_filters.script_name', 'active')
                : $builder->where(fn (Builder $q) => $q->where('sieve_filters.script_name', '<>', 'active')->orWhereNull('sieve_filters.script_name'));
        }
    }

    /**
     * Sorted by the requested column, then (for the default `mailbox`) the description, then the ID.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}
     */
    private function page(Builder $builder, ListQuery $query): array
    {
        $total = (clone $builder)->count();
        $builder->orderBy($query->sortColumn(), $query->order);

        if ($query->sort === 'mailbox') {
            $builder->orderBy('sieve_filters.script_desc', $query->order);
        }

        $rows = $builder->orderBy('sieve_filters.id', $query->order)->offset($query->offset)->limit($query->limit)->get()->all();

        return ['data' => array_map(fn (object $row): array => $this->present($row), $rows), 'meta' => $query->meta($total)];
    }

    private function row(string $mailbox, int $id, bool $lock = false): ?object
    {
        $query = $this->base()->where('sieve_filters.id', $id)->where('sieve_filters.username', $mailbox);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /**
     * @return array{id: int, mailbox: string, description: string, kind: string, script: string, active: bool, createdAt: string|null, modifiedAt: string|null, etag: string}
     */
    private function present(object $row): array
    {
        $filter = [
            'id' => (int) $row->id,
            'mailbox' => (string) $row->username,
            'description' => (string) $row->script_desc,
            // legacy always writes a kind; a row without one (in neither view) is reported as a prefilter.
            'kind' => $row->filter_type === 'postfilter' ? 'postfilter' : 'prefilter',
            'script' => (string) $row->script_data,
            'active' => $row->script_name === 'active',
            'createdAt' => MailcowDateTime::toUtcString($row->created),
            'modifiedAt' => MailcowDateTime::toUtcString($row->modified),
        ];

        return $filter + ['etag' => self::etag($filter)];
    }
}
