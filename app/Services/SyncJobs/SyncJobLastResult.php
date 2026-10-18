<?php

namespace App\Services\SyncJobs;

/**
 * The last result of a sync job (contract `SyncJobLastResult`), from the runner's `imapsync.success`
 * column: 1 succeeded, 0 failed, NULL unknown (never run, running, or cleared by a reschedule).
 */
enum SyncJobLastResult: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Unknown = 'unknown';

    public static function fromStored(int|string|null $success): self
    {
        return match (true) {
            $success === null => self::Unknown,
            (int) $success === 1 => self::Succeeded,
            default => self::Failed,
        };
    }
}
