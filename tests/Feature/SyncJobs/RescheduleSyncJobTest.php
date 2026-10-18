<?php

namespace Tests\Feature\SyncJobs;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\SyncJobs\ImapsyncRunnerSimulator;
use Tests\Support\SyncJobs\SyncJobTestHelpers;
use Tests\TestCase;

/**
 * Feature 008 T051 (FR-016, FR-021, FR-022, US3 AS-5): a reschedule clears the last run time and
 * the last result (legacy "Schedule next") so the runner selects the job on its next cycle; it
 * neither checks nor changes the version tag, works while a run is in progress, and is audited.
 */
class RescheduleSyncJobTest extends TestCase
{
    use ApiKeys;
    use SyncJobTestHelpers;

    private int $job;

    private ImapsyncRunnerSimulator $runner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runner = new ImapsyncRunnerSimulator;
        $this->syncMailbox('alice@example.org');
        $this->syncMailbox('carol@example.net');
        $this->job = $this->mailcow()->syncJob('alice@example.org', ['last_run' => DB::raw('NOW() - INTERVAL 1 HOUR'), 'success' => 0, 'exit_status' => 'EXIT_CONNECTION_FAILURE', 'mins_interval' => 120]);
    }

    public function test_the_last_run_and_result_are_cleared_and_the_tag_is_unchanged(): void
    {
        $this->asAdmin();
        $tag = $this->getJson('/api/v2/sync-jobs/'.$this->job)->headers->get('ETag');
        $this->assertNotContains($this->job, $this->runner->selectableIds());

        $response = $this->rescheduleSyncJob($this->job, ['If-Match' => '"stale"'])->assertOk();

        $this->assertSame(['lastRunAt' => null, 'lastResult' => 'unknown', 'exitStatus' => null, 'running' => false, 'logAvailable' => false], $response->json('data.runStatus'));
        $this->assertSame($tag, $response->headers->get('ETag'));
        $this->assertSame($tag, $response->json('data.etag'));
        $row = $this->syncJobRow($this->job);
        $this->assertNull($row?->last_run);
        $this->assertNull($row?->success);
        $this->assertContains($this->job, $this->runner->selectableIds());
    }

    public function test_a_reschedule_during_a_run_lets_the_run_finish_and_write_its_status(): void
    {
        $this->asAdmin();
        $this->runner->startRun($this->job);

        $this->rescheduleSyncJob($this->job)->assertOk()->assertJsonPath('data.runStatus.running', true);
        $this->runner->finishRun($this->job, 'done', 1, 'EX_OK');

        $this->assertNotNull($this->syncJobRow($this->job)?->last_run);
        $this->assertNotContains($this->job, $this->runner->selectableIds());
        DB::table('imapsync')->where('id', $this->job)->update(['last_run' => DB::raw('NOW() - INTERVAL 121 MINUTE')]);
        $this->assertContains($this->job, $this->runner->selectableIds());
    }

    public function test_foreign_jobs_callers_without_the_flag_and_read_only_keys_are_refused(): void
    {
        $foreign = $this->mailcow()->syncJob('carol@example.net', ['success' => 1]);
        $this->asDomainAdmin(['example.org']);
        $this->rescheduleSyncJob($foreign)->assertNotFound();

        DB::table('user_acl')->where('username', 'alice@example.org')->update(['syncjobs' => 0]);
        $this->signInAs('alice@example.org', Role::MailboxUser);
        $this->rescheduleSyncJob($this->job)->assertForbidden()->assertJsonPath('type', '/api/v2/problems/permission-flag-required');
        $this->forgetCookies();

        $this->mailcow()->admin('admin', 'Passw0rd!', 'SSHA256');
        $this->rescheduleSyncJob($this->job, $this->keyHeader($this->apiKey('admin', 'readOnly')))->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');

        $this->assertSame(1, (int) $this->syncJobRow($foreign)?->success);
        $this->assertSame(0, (int) $this->syncJobRow($this->job)?->success);
    }

    public function test_a_reschedule_is_audited_with_what_it_cleared(): void
    {
        $lastRun = (string) $this->syncJobRow($this->job)?->last_run;
        $this->asAdmin();
        $this->rescheduleSyncJob($this->job)->assertOk();

        $records = $this->auditOfTarget('syncJob', (string) $this->job);
        $this->assertCount(1, $records);
        $this->assertSame('syncJobReschedule', $records[0]->event_type);
        $this->assertSame(['lastRunAt', 'lastResult'], $records[0]->details['cleared']);
        $this->assertSame('failed', $records[0]->details['previous']['lastResult']);
        $this->assertSame(\Carbon\CarbonImmutable::parse($lastRun, 'Europe/Prague')->utc()->format('Y-m-d\TH:i:s\Z'), $records[0]->details['previous']['lastRunAt']);
        $this->assertSame('alice@example.org', $records[0]->details['mailbox']);
    }
}
