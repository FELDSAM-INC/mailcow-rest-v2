<?php

namespace Tests\Feature\SyncJobs;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\SyncJobs\ImapsyncRunnerSimulator;
use Tests\Support\SyncJobs\SyncJobTestHelpers;
use Tests\TestCase;

/**
 * Feature 008 T055 (FR-016, FR-021, US4 AS-1 to AS-4): a deletion removes the row at once, so the
 * runner no longer selects it; a run in progress is not interrupted and its final write has no
 * effect. Foreign or missing jobs are "not found", an outdated `If-Match` is `412`, and the deletion
 * is audited without the password.
 */
class DeleteSyncJobTest extends TestCase
{
    use ApiKeys;
    use SyncJobTestHelpers;

    private ImapsyncRunnerSimulator $runner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runner = new ImapsyncRunnerSimulator;
        $this->syncMailbox('alice@example.org');
        $this->syncMailbox('carol@example.net');
    }

    public function test_the_owner_deletes_a_job_and_the_runner_no_longer_selects_it(): void
    {
        $job = $this->mailcow()->syncJob('alice@example.org');
        $this->asSyncUser('alice@example.org');

        $response = $this->deleteSyncJob($job)->assertNoContent();

        $this->assertSame('', (string) $response->getContent());
        $this->assertNull($this->syncJobRow($job));
        $this->assertNotContains($job, $this->runner->selectableIds());
    }

    public function test_a_foreign_or_missing_job_is_not_found(): void
    {
        $foreign = $this->mailcow()->syncJob('carol@example.net');
        $this->asSyncUser('alice@example.org');

        $this->deleteSyncJob($foreign)->assertNotFound();
        $this->deleteSyncJob(2147483647)->assertNotFound();
        $this->assertNotNull($this->syncJobRow($foreign));
    }

    public function test_a_job_deleted_during_its_run_stays_deleted(): void
    {
        $job = $this->mailcow()->syncJob('alice@example.org');
        $this->runner->startRun($job);
        $this->asAdmin();

        $this->deleteSyncJob($job)->assertNoContent();

        $this->assertSame(0, $this->runner->finishRun($job, 'Exiting with return value 0', 1, 'EX_OK'));
        $this->assertNull($this->syncJobRow($job));
    }

    public function test_callers_without_the_flag_and_read_only_keys_are_refused(): void
    {
        $job = $this->mailcow()->syncJob('alice@example.org');
        DB::table('user_acl')->where('username', 'alice@example.org')->update(['syncjobs' => 0]);
        $this->signInAs('alice@example.org', Role::MailboxUser);
        $this->deleteSyncJob($job)->assertForbidden()->assertJsonPath('type', '/api/v2/problems/permission-flag-required');
        $this->forgetCookies();

        $this->mailcow()->admin('admin', 'Passw0rd!', 'SSHA256');
        $this->deleteSyncJob($job, $this->keyHeader($this->apiKey('admin', 'readOnly')))->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');

        $this->assertNotNull($this->syncJobRow($job));
    }

    public function test_an_outdated_tag_keeps_the_job_and_a_current_one_deletes_it(): void
    {
        $job = $this->mailcow()->syncJob('alice@example.org');
        $this->asAdmin();
        $tag = (string) $this->getJson('/api/v2/sync-jobs/'.$job)->headers->get('ETag');

        $this->deleteSyncJob($job, ['If-Match' => '"outdated"'])->assertStatus(412);
        $this->assertNotNull($this->syncJobRow($job));
        $this->deleteSyncJob($job, ['If-Match' => $tag])->assertNoContent();
    }

    public function test_jobs_in_every_run_state_delete_alike_and_each_deletion_is_audited(): void
    {
        $f = $this->mailcow();
        $jobs = [
            $f->syncJob('alice@example.org', ['user1' => 'never']),
            $f->syncJob('alice@example.org', ['user1' => 'ok', 'success' => 1, 'last_run' => '2026-10-18 08:00:00', 'returned_text' => 'log']),
            $f->syncJob('alice@example.org', ['user1' => 'failed', 'success' => 0, 'last_run' => '2026-10-18 08:00:00', 'password1' => self::REMOTE_PASSWORD]),
        ];
        $this->asAdmin();

        foreach ($jobs as $job) {
            $this->deleteSyncJob($job)->assertNoContent();
        }

        $this->assertSame(0, $this->syncJobCount());
        $records = $this->auditOf('syncJobDeletion');
        $this->assertCount(3, $records);
        $this->assertSame([(string) $jobs[0], 'alice@example.org'], [$records[0]->target_id, $records[0]->target_name]);
        $this->assertSame(['mailbox' => 'alice@example.org', 'remoteHost' => 'imap.example.net', 'remoteUser' => 'failed'], $records[2]->details);
        $this->assertStringNotContainsString(self::REMOTE_PASSWORD, (string) DB::table('restv2_audit_events')->get()->toJson());
    }
}
