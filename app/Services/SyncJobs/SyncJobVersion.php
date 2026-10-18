<?php

namespace App\Services\SyncJobs;

use App\Models\Mailcow\SyncJob;
use App\Support\Http\ETag;
use Illuminate\Support\Facades\DB;

/**
 * Version tags of sync jobs (feature 008 research R4, FR-020): 001's strong ETag over the stored
 * configuration of the `imapsync` row, in a fixed order, with numbers and flags normalised. The
 * remote password enters only as an HMAC keyed with the app key, so that a reader of the tag can
 * never test password guesses against it. The runner's status columns, the times and the columns
 * no client sees are left out, so a run or a reschedule never makes an editor's tag outdated.
 * Single reads, list entries and bulk items use the same function.
 */
class SyncJobVersion
{
    /** The flag columns of the configuration, in tag order. */
    private const FLAGS = ['delete1', 'delete2', 'delete2duplicates', 'automap', 'skipcrossduplicates', 'subscribeall', 'dry'];

    public function forRow(object $row): string
    {
        return ETag::of($this->input($row));
    }

    public function of(SyncJob $job): string
    {
        return $this->forRow((object) $job->getAttributes());
    }

    /** The tag of a stored job, or null when it does not exist. */
    public function ofId(int $id): ?string
    {
        $row = DB::table('imapsync')->where('id', $id)->first();

        return $row === null ? null : $this->forRow($row);
    }

    /**
     * The canonical values the tag is computed over.
     *
     * @return list<string|int>
     */
    public function input(object $row): array
    {
        $flag = fn (string $column): string => (int) ($row->{$column} ?? 0) !== 0 ? '1' : '0';

        return [
            (int) $row->id,
            (string) $row->user2,
            (string) $row->host1,
            (int) $row->port1,
            (string) ($row->enc1 ?? ''),
            (string) $row->user1,
            hash_hmac('sha256', (string) $row->password1, (string) config('app.key')),
            (string) $row->exclude,
            (string) $row->subfolder2,
            (int) $row->mins_interval,
            (int) $row->maxage,
            (int) $row->maxbytespersecond,
            (int) $row->timeout1,
            (int) $row->timeout2,
            ...array_map($flag, self::FLAGS),
            (string) $row->custom_params,
            $flag('active'),
        ];
    }
}
