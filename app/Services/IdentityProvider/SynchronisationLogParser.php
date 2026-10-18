<?php

namespace App\Services\IdentityProvider;

use Carbon\CarbonImmutable;

/**
 * Derives the state of a legacy synchronisation job from its scheduled-job log entries (research
 * R12, FR-062), read only. The texts are those of mailcow-dockerized 2026-09a
 * (data/conf/phpfpm/crons/keycloak-sync.php and ldap-sync.php, listed in
 * tests/fixtures/cron-log/2026-09a-messages.json by scripts/legacy/extract-sync-log-texts.sh):
 *
 *  - `DONE!` (info) ends a completed run;
 *  - `Sync not ready (…)` (warning) is a scheduler tick while the interval has not elapsed;
 *  - `Sync is already running` (warning) is a tick while a run is in progress;
 *  - per-user messages are progress of a run;
 *  - any other `err` entry is a run-level failure (the job exits).
 *
 * The scheduler starts the jobs every minute at second 0; a job runs when at least `syncInterval`
 * minutes passed since the end of its previous completed run, or at the next tick after a failure
 * (the lock file then holds no end time).
 */
final class SynchronisationLogParser
{
    public const COMPLETED = 'completed';

    public const TICK = 'tick';

    public const ALREADY_RUNNING = 'alreadyRunning';

    public const PROGRESS = 'progress';

    public const FAILURE = 'failure';

    public const OTHER = 'other';

    /** A run counts as in progress while its last progress is at most this many seconds old. */
    public const RUNNING_WINDOW = 120;

    /** Prefixes of per-user progress messages (info, warning and the per-user `err`). */
    private const PROGRESS_PREFIXES = [
        'Creating user ',
        'Syncing attributes for user ',
        'Skipping user ',
        'Using default template for user ',
        'No matching attribute mapping found for user ',
        'No email address in keycloak found for user ',
        'Could not create user ',
    ];

    /**
     * @param  array{priority?: mixed, message?: mixed}  $entry
     */
    public static function classify(array $entry): string
    {
        $message = is_string($entry['message'] ?? null) ? $entry['message'] : '';
        $priority = is_string($entry['priority'] ?? null) ? $entry['priority'] : '';

        if ($message === 'DONE!') {
            return self::COMPLETED;
        }

        if (str_starts_with($message, 'Sync not ready (')) {
            return self::TICK;
        }

        if ($message === 'Sync is already running') {
            return self::ALREADY_RUNNING;
        }

        foreach (self::PROGRESS_PREFIXES as $prefix) {
            if (str_starts_with($message, $prefix)) {
                return self::PROGRESS;
            }
        }

        return $priority === 'err' ? self::FAILURE : self::OTHER;
    }

    /**
     * The status of the job named `task` from log entries (any order; entries of other tasks and
     * entries after `now` are ignored).
     *
     * @param  iterable<array<string, mixed>>  $entries
     * @return array{lastCompletedAt: CarbonImmutable|null, lastFailedAt: CarbonImmutable|null, state: string, nextRunExpectedAt: CarbonImmutable|null}
     */
    public static function derive(iterable $entries, string $task, int $syncInterval, CarbonImmutable $now): array
    {
        $nowTs = $now->getTimestamp();
        $completed = null;
        $failures = [];
        $activity = [];
        $seen = false;

        foreach ($entries as $entry) {
            if (($entry['task'] ?? null) !== $task || ! is_numeric($entry['time'] ?? null) || (int) $entry['time'] > $nowTs) {
                continue;
            }

            $seen = true;
            $time = (int) $entry['time'];

            match (self::classify($entry)) {
                self::COMPLETED => $completed = max($completed ?? $time, $time),
                self::FAILURE => $failures[] = $time,
                self::PROGRESS, self::ALREADY_RUNNING => $activity[] = $time,
                default => null,
            };
        }

        if (! $seen) {
            return ['lastCompletedAt' => null, 'lastFailedAt' => null, 'state' => 'unknown', 'nextRunExpectedAt' => null];
        }

        $after = fn (int $t): bool => $completed === null || $t > $completed;
        $failed = array_values(array_filter($failures, $after));
        $lastFailed = $failed === [] ? null : max($failed);
        $since = max($completed ?? PHP_INT_MIN, $lastFailed ?? PHP_INT_MIN);
        $recent = array_filter($activity, fn (int $t): bool => $t > $since && $nowTs - $t <= self::RUNNING_WINDOW);

        $next = match (true) {
            $lastFailed !== null => intdiv($lastFailed, 60) * 60 + 60,
            $completed !== null => self::ceilMinute($completed + max(1, $syncInterval) * 60),
            default => null,
        };

        $state = match (true) {
            $recent !== [] => 'running',
            $next === null => 'unknown',
            $nowTs - $next > self::RUNNING_WINDOW => 'overdue',
            default => 'waiting',
        };

        $time = fn (?int $t): ?CarbonImmutable => $t === null ? null : CarbonImmutable::createFromTimestampUTC($t);

        return [
            'lastCompletedAt' => $time($completed),
            'lastFailedAt' => $time($lastFailed),
            'state' => $state,
            'nextRunExpectedAt' => $time($next),
        ];
    }

    private static function ceilMinute(int $timestamp): int
    {
        return $timestamp % 60 === 0 ? $timestamp : intdiv($timestamp, 60) * 60 + 60;
    }
}
