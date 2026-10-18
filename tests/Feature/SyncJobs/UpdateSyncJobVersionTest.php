<?php

namespace Tests\Feature\SyncJobs;

use Illuminate\Support\Facades\DB;
use Tests\Support\SyncJobs\ImapsyncRunnerSimulator;
use Tests\Support\SyncJobs\SyncJobTestHelpers;
use Tests\TestCase;

/**
 * Feature 008 T048 (FR-020, FR-021, FR-028, US3 AS-3, SC-005): an outdated `If-Match` refuses a
 * change with `412` and changes nothing. Configuration changes made by legacy outdate the tag; the
 * runner's status writes and a reschedule do not.
 */
class UpdateSyncJobVersionTest extends TestCase
{
    use SyncJobTestHelpers;

    private int $job;

    protected function setUp(): void
    {
        parent::setUp();

        $this->syncMailbox('alice@example.org');
        $this->job = $this->mailcow()->syncJob('alice@example.org');
        $this->asAdmin();
    }

    private function etag(): string
    {
        return (string) $this->getJson('/api/v2/sync-jobs/'.$this->job)->assertOk()->headers->get('ETag');
    }

    public function test_the_second_of_two_clients_with_the_same_tag_is_refused(): void
    {
        $tag = $this->etag();

        $this->patchSyncJob($this->job, ['intervalMinutes' => 30], ['If-Match' => $tag])->assertOk();
        $this->patchSyncJob($this->job, ['intervalMinutes' => 40], ['If-Match' => $tag])->assertStatus(412)->assertJsonPath('type', 'about:blank');

        $this->assertSame(30, (int) $this->syncJobRow($this->job)?->mins_interval);
    }

    public function test_legacy_changes_outdate_the_tag_and_the_next_read_shows_them_without_the_password(): void
    {
        $tag = $this->etag();
        DB::table('imapsync')->where('id', $this->job)->update(['mins_interval' => 45]);
        $this->patchSyncJob($this->job, ['active' => false], ['If-Match' => $tag])->assertStatus(412);

        $tag = $this->etag();
        DB::table('imapsync')->where('id', $this->job)->update(['password1' => 'changed-in-legacy']);
        $this->patchSyncJob($this->job, ['active' => false], ['If-Match' => $tag])->assertStatus(412);

        $read = $this->getJson('/api/v2/sync-jobs/'.$this->job)->assertOk();
        $this->assertSame(45, $read->json('data.intervalMinutes'));
        $this->assertTrue($read->json('data.remotePasswordSet'));
        $this->assertStringNotContainsString('changed-in-legacy', (string) $read->getContent());
        $this->assertSame(1, (int) $this->syncJobRow($this->job)?->active);
    }

    public function test_runner_writes_and_a_reschedule_keep_the_tag(): void
    {
        $tag = $this->etag();
        $runner = new ImapsyncRunnerSimulator;
        $runner->startRun($this->job);
        $runner->finishRun($this->job, 'log', 1, 'EX_OK');
        $this->rescheduleSyncJob($this->job)->assertOk();

        $this->patchSyncJob($this->job, ['intervalMinutes' => 30], ['If-Match' => $tag])->assertOk();
    }

    public function test_a_wildcard_matches_and_an_outdated_tag_is_answered_before_an_invalid_body(): void
    {
        $this->patchSyncJob($this->job, ['intervalMinutes' => 30], ['If-Match' => '*'])->assertOk();

        $this->patchSyncJob($this->job, ['exclude' => '('], ['If-Match' => '"outdated"'])->assertStatus(412);
        $this->assertSame(30, (int) $this->syncJobRow($this->job)?->mins_interval);
    }
}
