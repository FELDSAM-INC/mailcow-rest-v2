<?php

namespace App\Services\SyncJobs;

use App\Auth\Principal;
use App\Models\Mailcow\SyncJob;
use App\Support\Http\ListQuery;
use Illuminate\Support\Facades\DB;

/**
 * Reads of sync jobs (feature 008 FR-011–FR-015, research R19): the list in the caller's scope
 * (SyncJobScope), with the filters, the substring search and the sorts of the contract, and the log
 * of one job. The log (`returned_text`, a LONGTEXT that can be megabytes long) is never selected by
 * the list: whether a job has one is looked up for the page's identifiers only.
 */
class SyncJobQuery
{
    /** Contract sort value => column; ties are broken by `imapsync.id` in the same direction. */
    public const SORTS = [
        'id' => 'imapsync.id',
        'mailbox' => 'imapsync.user2',
        'remoteHost' => 'imapsync.host1',
        'remoteUser' => 'imapsync.user1',
        'intervalMinutes' => 'imapsync.mins_interval',
        'active' => 'imapsync.active',
        'lastRunAt' => 'imapsync.last_run',
        'lastResult' => 'imapsync.success',
        'createdAt' => 'imapsync.created',
        'modifiedAt' => 'imapsync.modified',
    ];

    /** Every `imapsync` column but the log. */
    private const COLUMNS = [
        'id', 'user2', 'host1', 'authmech1', 'regextrans2', 'authmd51', 'domain2', 'subfolder2', 'user1',
        'password1', 'exclude', 'maxage', 'mins_interval', 'maxbytespersecond', 'port1', 'enc1',
        'delete2duplicates', 'delete1', 'delete2', 'automap', 'skipcrossduplicates', 'custom_params',
        'timeout1', 'timeout2', 'subscribeall', 'dry', 'is_running', 'last_run', 'success', 'exit_status',
        'created', 'modified', 'active',
    ];

    public function __construct(private readonly SyncJobScope $scope) {}

    /**
     * Every column but the log, qualified.
     *
     * @return list<string>
     */
    public static function columns(): array
    {
        return array_map(fn (string $column): string => 'imapsync.'.$column, self::COLUMNS);
    }

    /**
     * One page of the caller's jobs, with the total of matches and whether each has a log.
     *
     * @param  array{mailbox: string|null, domain: string|null, active: bool|null, running: bool|null, lastResult: string|null}  $filters
     * @return array{jobs: list<SyncJob>, total: int, logs: array<int, bool>}
     */
    public function page(Principal $principal, ListQuery $list, array $filters): array
    {
        $query = $this->scope->query($principal);

        if ($filters['mailbox'] !== null) {
            $query->where('imapsync.user2', $filters['mailbox']);
        }

        if ($filters['domain'] !== null) {
            $query->where('m.domain', $filters['domain']);
        }

        foreach (['active' => 'imapsync.active', 'running' => 'imapsync.is_running'] as $filter => $column) {
            if ($filters[$filter] !== null) {
                $query->where($column, $filters[$filter] ? '<>' : '=', 0);
            }
        }

        match ($filters['lastResult']) {
            'succeeded' => $query->where('imapsync.success', 1),
            'failed' => $query->whereNotNull('imapsync.success')->where('imapsync.success', '<>', 1),
            'unknown' => $query->whereNull('imapsync.success'),
            default => null,
        };

        $list->applySearch($query, ['imapsync.user2', 'imapsync.user1', 'imapsync.host1']);
        $page = $list->paginate($query, null, 'imapsync.id');
        /** @var list<SyncJob> $jobs */
        $jobs = $page['data'];

        return [
            'jobs' => $jobs,
            'total' => $page['meta']['total'],
            'logs' => $this->logAvailability(array_map(fn (SyncJob $job): int => (int) $job->id, $jobs)),
        ];
    }

    /**
     * Whether each job has a stored, non-empty log, in one query over the given identifiers.
     *
     * @param  list<int>  $ids
     * @return array<int, bool>
     */
    public function logAvailability(array $ids): array
    {
        $available = array_fill_keys($ids, false);

        if ($ids === []) {
            return $available;
        }

        foreach (DB::table('imapsync')->whereIn('id', $ids)->whereNotNull('returned_text')->where('returned_text', '<>', '')->pluck('id') as $id) {
            $available[(int) $id] = true;
        }

        return $available;
    }

    /**
     * A job in the caller's scope together with its log (`returned_text`), or null.
     */
    public function findWithLog(int $id, Principal $principal): ?SyncJob
    {
        return $this->scope->query($principal)->addSelect('imapsync.returned_text')->where('imapsync.id', $id)->first();
    }
}
