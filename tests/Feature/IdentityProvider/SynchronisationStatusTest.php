<?php

namespace Tests\Feature\IdentityProvider;

use Carbon\CarbonImmutable;
use Tests\Support\IdentityProvider\IdentityProviderTestHelpers;
use Tests\TestCase;

/**
 * T037 (FR-062, US1 AS-10): the synchronisation status derived from mailcow's scheduled-job log.
 */
class SynchronisationStatusTest extends TestCase
{
    use IdentityProviderTestHelpers;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        // A fixed time after the real one: cookies set with a travelled expiry in the past would
        // count as cleared.
        $this->now = CarbonImmutable::parse('2027-06-15 12:00:30', 'UTC');
        $this->travelTo($this->now);
        $this->mailcow()->ldapConfig(['periodic_sync' => '1', 'sync_interval' => '15']);
        $this->asAdmin();
    }

    /**
     * @return array{time: int, priority: string, task: string, message: string}
     */
    private function entry(int $secondsAgo, string $message, string $priority = 'info', string $task = 'LDAP Sync'): array
    {
        return ['time' => $this->now->getTimestamp() - $secondsAgo, 'priority' => $priority, 'task' => $task, 'message' => $message];
    }

    /**
     * @return array<string, mixed>
     */
    private function syncStatus(): array
    {
        return (array) $this->getJson('/api/v2/identity-provider')->assertOk()->json('data.synchronisation');
    }

    public function test_a_completed_run_four_minutes_ago_is_waiting_for_the_next(): void
    {
        $this->mailcow()->cronLog([
            $this->entry(250, 'Syncing attributes for user carl@example.org'),
            $this->entry(240, 'DONE!'),
            $this->entry(30, 'Sync not ready (3.50min / 15min)', 'warning'),
        ]);

        $this->assertSame([
            'enabled' => true,
            'state' => 'waiting',
            'lastCompletedAt' => '2027-06-15T11:56:30Z',
            'lastFailedAt' => null,
            'nextRunExpectedAt' => '2027-06-15T12:12:00Z',
        ], $this->syncStatus());
    }

    public function test_progress_thirty_seconds_ago_is_running(): void
    {
        $this->mailcow()->cronLog([
            $this->entry(1200, 'DONE!'),
            $this->entry(30, 'Creating user dana@example.org'),
        ]);

        $this->assertSame('running', $this->syncStatus()['state']);
    }

    public function test_a_failure_after_the_last_completion_expects_the_next_minute(): void
    {
        $this->mailcow()->cronLog([
            $this->entry(600, 'DONE!'),
            $this->entry(20, 'Received HTTP 401', 'err'),
        ]);

        $status = $this->syncStatus();
        $this->assertSame('2027-06-15T12:00:10Z', $status['lastFailedAt']);
        $this->assertSame('2027-06-15T12:01:00Z', $status['nextRunExpectedAt']);
        $this->assertSame('waiting', $status['state']);
    }

    public function test_nothing_for_thirty_minutes_is_overdue(): void
    {
        $this->mailcow()->cronLog([$this->entry(1800, 'DONE!')]);

        $this->assertSame('overdue', $this->syncStatus()['state']);
    }

    public function test_an_empty_log_is_unknown(): void
    {
        $this->assertSame(['enabled' => true, 'state' => 'unknown', 'lastCompletedAt' => null, 'lastFailedAt' => null, 'nextRunExpectedAt' => null], $this->syncStatus());
    }

    public function test_import_and_synchronisation_off_is_disabled(): void
    {
        $this->mailcow()->identityProviderRows(['periodic_sync' => '0', 'import_users' => '0']);
        $this->mailcow()->cronLog([$this->entry(60, 'DONE!')]);

        $this->assertSame(['enabled' => false, 'state' => 'disabled', 'lastCompletedAt' => null, 'lastFailedAt' => null, 'nextRunExpectedAt' => null], $this->syncStatus());
    }

    public function test_the_other_jobs_entries_are_ignored(): void
    {
        $this->mailcow()->cronLog([
            $this->entry(240, 'DONE!', 'info', 'Keycloak Sync'),
            $this->entry(30, 'Creating user x@example.org', 'info', 'Keycloak Sync'),
        ]);

        $this->assertSame('unknown', $this->syncStatus()['state']);
    }

    public function test_the_version_tag_does_not_change_with_the_log(): void
    {
        $before = $this->getJson('/api/v2/identity-provider')->json('data.etag');
        $this->mailcow()->cronLog([$this->entry(10, 'DONE!')]);

        $this->assertSame($before, $this->getJson('/api/v2/identity-provider')->assertOk()->json('data.etag'));
    }

    public function test_the_status_is_found_beyond_the_first_chunk(): void
    {
        $entries = [$this->entry(900, 'DONE!')];

        for ($i = 0; $i < 600; $i++) {
            $entries[] = ['time' => $this->now->getTimestamp() - 800 + $i, 'priority' => 'info', 'task' => 'Rspamd', 'message' => 'noise'];
        }

        $this->mailcow()->cronLog($entries);

        $this->assertSame('2027-06-15T11:45:30Z', $this->syncStatus()['lastCompletedAt']);
    }
}
