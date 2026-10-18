<?php

namespace Tests\Support\SyncJobs;

use Illuminate\Support\Facades\DB;

/**
 * The SQL of mailcow's imapsync runner (feature 008 research R20), copied from
 * data/Dockerfiles/dovecot/imapsync_runner.pl at mailcow-dockerized 2026-09a, so that tests can
 * assert what the unchanged runner selects and how its status writes interact with the API's
 * changes. The runner itself is never started; this API never calls it.
 */
final class ImapsyncRunnerSimulator
{
    /**
     * The jobs the runner would start on its next cycle, in its order (`ORDER BY last_run`).
     *
     * @return list<int>
     */
    public function selectableIds(): array
    {
        return array_map('intval', array_column(DB::select('SELECT id
            FROM imapsync
              WHERE active = 1
                AND is_running = 0
                AND (
                  UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(last_run) > mins_interval * 60
                  OR
                  last_run IS NULL)
          ORDER BY last_run'), 'id'));
    }

    /** The runner marks a job as running before it starts imapsync. */
    public function startRun(int $id): void
    {
        DB::update('UPDATE imapsync SET is_running = 1, success = NULL, exit_status = NULL WHERE id = ?', [$id]);
    }

    /**
     * The runner's two final writes after imapsync exited; returns the affected rows of the last
     * one (0 when the job was deleted meanwhile).
     */
    public function finishRun(int $id, string $stdout, int $success, ?string $exitStatus): int
    {
        DB::update('UPDATE imapsync SET returned_text = ?, success = ?, exit_status = ? WHERE id = ?', [$stdout, $success, $exitStatus, $id]);

        return DB::update('UPDATE imapsync SET last_run = NOW(), is_running = 0 WHERE id = ?', [$id]);
    }

    /** The runner's first statement when its container starts: no job is running any more. */
    public function restart(): void
    {
        DB::update('UPDATE imapsync SET is_running = 0');
    }
}
