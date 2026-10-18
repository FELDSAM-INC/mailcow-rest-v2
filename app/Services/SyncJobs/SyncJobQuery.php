<?php

namespace App\Services\SyncJobs;

/**
 * Reads of sync jobs (feature 008 research R19). The log (`returned_text`, a LONGTEXT that can be
 * megabytes long) is never selected by lists; it is read on its own.
 */
class SyncJobQuery
{
    /** Every `imapsync` column but the log, qualified. */
    private const COLUMNS = [
        'id', 'user2', 'host1', 'authmech1', 'regextrans2', 'authmd51', 'domain2', 'subfolder2', 'user1',
        'password1', 'exclude', 'maxage', 'mins_interval', 'maxbytespersecond', 'port1', 'enc1',
        'delete2duplicates', 'delete1', 'delete2', 'automap', 'skipcrossduplicates', 'custom_params',
        'timeout1', 'timeout2', 'subscribeall', 'dry', 'is_running', 'last_run', 'success', 'exit_status',
        'created', 'modified', 'active',
    ];

    /**
     * @return list<string>
     */
    public static function columns(): array
    {
        return array_map(fn (string $column): string => 'imapsync.'.$column, self::COLUMNS);
    }
}
