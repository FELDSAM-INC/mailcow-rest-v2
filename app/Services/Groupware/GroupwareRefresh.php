<?php

namespace App\Services\Groupware;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The incremental groupware directory update of a change (feature 004 research R9): 003's
 * SogoDirectory::refresh() of the affected mailboxes inside the change's transaction, in a
 * savepoint, so that a failure rolls back only the directory rows and never the change (FR-034).
 * The outcome goes into the change's audit record; the caller flushes the cache after its commit
 * when the outcome is `done` (GroupwareCacheFlush).
 */
class GroupwareRefresh
{
    public function __construct(private readonly SogoDirectory $directory) {}

    /**
     * @param  list<string>  $usernames
     */
    public function inSavepoint(array $usernames): GroupwareOutcome
    {
        if (config('restv2.skip_sogo')) {
            return GroupwareOutcome::GroupwareDisabled;
        }

        try {
            return DB::transaction(fn (): GroupwareOutcome => $this->directory->refresh(array_values(array_unique($usernames))));
        } catch (Throwable $e) {
            Log::warning('The groupware directory entries were not updated: '.$e->getMessage());

            return GroupwareOutcome::Failed;
        }
    }
}
