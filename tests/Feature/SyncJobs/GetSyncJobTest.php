<?php

namespace Tests\Feature\SyncJobs;

use App\Auth\Role;
use Tests\Support\SyncJobs\ImapsyncRunnerSimulator;
use Tests\Support\SyncJobs\SyncJobTestHelpers;
use Tests\TestCase;

/**
 * Feature 008 T023 (FR-007, FR-008, US1 AS-3, AS-4, AS-5): a single job shows its configuration,
 * version tag and the run status the runner maintains; a job outside the caller's scope is the
 * same "not found" as one that does not exist.
 */
class GetSyncJobTest extends TestCase
{
    use SyncJobTestHelpers;

    private ImapsyncRunnerSimulator $runner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runner = new ImapsyncRunnerSimulator;
        $this->syncMailbox('alice@example.org');
        $this->syncMailbox('carol@example.net');
    }

    public function test_a_job_whose_last_run_failed_shows_the_failure_and_its_log(): void
    {
        $id = $this->mailcow()->syncJob('alice@example.org');
        $this->runner->startRun($id);
        $this->runner->finishRun($id, "Host1 failure: Error login on [imap.example.net] with user [remote@example.net] auth [LOGIN]: 2 NO [AUTHENTICATIONFAILED]\n", 0, 'EXIT_AUTHENTICATION_FAILURE_USER1');
        $this->asAdmin();

        $response = $this->getJson('/api/v2/sync-jobs/'.$id)->assertOk();

        $this->assertSame('failed', $response->json('data.runStatus.lastResult'));
        $this->assertSame('EXIT_AUTHENTICATION_FAILURE_USER1', $response->json('data.runStatus.exitStatus'));
        $this->assertTrue($response->json('data.runStatus.logAvailable'));
        $this->assertFalse($response->json('data.runStatus.running'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $response->json('data.runStatus.lastRunAt'));
        $this->assertSame($response->headers->get('ETag'), $response->json('data.etag'));
        $this->assertStringNotContainsString('remote-secret', (string) $response->getContent());
    }

    public function test_a_never_run_job_and_a_running_job(): void
    {
        $never = $this->mailcow()->syncJob('alice@example.org');
        $running = $this->mailcow()->syncJob('alice@example.org', ['user1' => 'other', 'last_run' => '2026-10-18 08:00:00', 'success' => 1]);
        $this->runner->startRun($running);
        $this->asAdmin();

        $this->getJson('/api/v2/sync-jobs/'.$never)->assertOk()
            ->assertJsonPath('data.runStatus', ['lastRunAt' => null, 'lastResult' => 'unknown', 'exitStatus' => null, 'running' => false, 'logAvailable' => false]);
        $this->getJson('/api/v2/sync-jobs/'.$running)->assertOk()
            ->assertJsonPath('data.runStatus.running', true)
            ->assertJsonPath('data.runStatus.lastResult', 'unknown');
    }

    public function test_a_foreign_job_is_the_same_not_found_as_a_missing_one(): void
    {
        $foreign = $this->mailcow()->syncJob('carol@example.net');
        $this->asDomainAdmin(['example.org']);

        $outside = $this->getJson('/api/v2/sync-jobs/'.$foreign)->assertNotFound();
        $missing = $this->getJson('/api/v2/sync-jobs/2147483647')->assertNotFound();

        $this->assertSame('about:blank', $outside->json('type'));
        $this->assertSame($missing->json('type'), $outside->json('type'));
        $this->assertSame($missing->json('title'), $outside->json('title'));
        $this->assertSame($missing->json('detail'), $outside->json('detail'));
        $this->assertSame(array_keys((array) $missing->json()), array_keys((array) $outside->json()));
    }

    public function test_a_mailbox_user_reads_only_their_own_job(): void
    {
        $own = $this->mailcow()->syncJob('alice@example.org');
        $other = $this->mailcow()->syncJob('carol@example.net');
        $this->signInAs('alice@example.org', Role::MailboxUser);

        $this->getJson('/api/v2/sync-jobs/'.$own)->assertOk()->assertJsonPath('data.mailbox', 'alice@example.org');
        $this->getJson('/api/v2/sync-jobs/'.$other)->assertNotFound();
    }

    public function test_an_invalid_identifier_is_not_found(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $this->getJson('/api/v2/sync-jobs/abc')->assertNotFound()->assertJsonPath('type', 'about:blank');
        $this->getJson('/api/v2/sync-jobs/0')->assertNotFound();
    }
}
