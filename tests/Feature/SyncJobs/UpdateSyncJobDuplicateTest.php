<?php

namespace Tests\Feature\SyncJobs;

use Illuminate\Support\Facades\DB;
use Tests\Support\SyncJobs\SyncJobTestHelpers;
use Tests\TestCase;

/**
 * Feature 008 T049 (FR-017): an edit that would make a job a duplicate of another job of the same
 * mailbox is `409 sync-job-exists`; the duplicate check (and its named lock) runs only when the
 * remote user or host changes.
 */
class UpdateSyncJobDuplicateTest extends TestCase
{
    use SyncJobTestHelpers;

    public function test_an_edit_that_duplicates_another_job_of_the_mailbox_is_a_conflict(): void
    {
        $this->syncMailbox('alice@example.org');
        $this->syncMailbox('bob@example.org');
        $job = $this->mailcow()->syncJob('alice@example.org', ['host1' => 'one.example', 'user1' => 'one']);
        $other = $this->mailcow()->syncJob('alice@example.org', ['host1' => 'two.example', 'user1' => 'two']);
        $this->mailcow()->syncJob('bob@example.org', ['host1' => 'three.example', 'user1' => 'three']);
        $this->asAdmin();

        $this->patchSyncJob($job, ['remoteHost' => 'two.example', 'remoteUser' => 'two'])->assertStatus(409)
            ->assertJsonPath('type', '/api/v2/problems/sync-job-exists')
            ->assertJsonPath('syncJobId', $other);
        $this->assertSame('one.example', $this->syncJobRow($job)?->host1);

        $this->patchSyncJob($job, ['remoteHost' => 'three.example', 'remoteUser' => 'three'])->assertOk();
    }

    public function test_an_edit_of_other_members_takes_no_lock(): void
    {
        $this->syncMailbox('alice@example.org');
        $job = $this->mailcow()->syncJob('alice@example.org');
        $this->asAdmin();
        $headers = $this->csrf();

        DB::enableQueryLog();
        $this->patchMerge('/api/v2/sync-jobs/'.$job, ['intervalMinutes' => 30], $headers)->assertOk();
        $queries = implode("\n", array_column(DB::getQueryLog(), 'query'));
        DB::disableQueryLog();

        $this->assertStringNotContainsString('GET_LOCK', $queries);
    }
}
