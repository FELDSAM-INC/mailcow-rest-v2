<?php

namespace Tests\Feature\SyncJobs;

use Tests\Support\SyncJobs\SyncJobTestHelpers;
use Tests\TestCase;

/**
 * Feature 008 T037 (FR-017, US2 AS-3): a job for the same mailbox, remote user and remote host is a
 * conflict (`409 sync-job-exists` naming the existing job), compared with the database collation
 * as legacy compares it; the same remote account for another mailbox is a different job.
 */
class CreateSyncJobDuplicateTest extends TestCase
{
    use SyncJobTestHelpers;

    private int $existing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->syncMailbox('alice@example.org');
        $this->syncMailbox('bob@example.org');
        $this->existing = $this->mailcow()->syncJob('alice@example.org', ['host1' => 'imap.old-provider.example', 'user1' => 'alice.old']);
        $this->asAdmin();
    }

    public function test_an_identical_job_is_a_conflict_naming_the_existing_one(): void
    {
        $this->createSyncJob(self::syncJobBody(['mailbox' => 'alice@example.org']))->assertStatus(409)
            ->assertJsonPath('type', '/api/v2/problems/sync-job-exists')
            ->assertJsonPath('syncJobId', $this->existing);

        $this->assertSame(1, $this->syncJobCount());
    }

    public function test_the_host_and_user_are_compared_case_insensitively_like_legacy(): void
    {
        $this->createSyncJob(self::syncJobBody(['mailbox' => 'alice@example.org', 'remoteHost' => 'IMAP.Old-Provider.example']))->assertStatus(409);
        $this->createSyncJob(self::syncJobBody(['mailbox' => 'ALICE@example.org', 'remoteUser' => 'Alice.Old']))->assertStatus(409)
            ->assertJsonPath('syncJobId', $this->existing);

        $this->assertSame(1, $this->syncJobCount());
    }

    public function test_the_same_remote_account_for_another_mailbox_is_accepted(): void
    {
        $this->createSyncJob(self::syncJobBody(['mailbox' => 'bob@example.org']))->assertCreated();

        $this->assertSame(2, $this->syncJobCount());
    }
}
