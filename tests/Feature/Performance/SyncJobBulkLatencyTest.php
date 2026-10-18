<?php

namespace Tests\Feature\Performance;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\SyncJobs\SyncJobTestHelpers;
use Tests\TestCase;

/**
 * Feature 008 T065 (SC-009): a bulk deactivation of 100 sync jobs finishes within 10 s and reports
 * exactly one result per job, with one audit record each.
 */
#[Group('performance')]
class SyncJobBulkLatencyTest extends TestCase
{
    use SyncJobTestHelpers;

    public function test_a_bulk_action_on_100_jobs_finishes_within_ten_seconds(): void
    {
        $this->syncMailbox('alice@example.org');
        $ids = [];

        for ($i = 0; $i < 100; $i++) {
            $ids[] = $this->mailcow()->syncJob('alice@example.org', ['user1' => 'remote'.$i]);
        }

        $this->asAdmin();
        $headers = $this->csrf();
        $start = hrtime(true);
        $response = $this->postJson('/api/v2/sync-jobs/bulk-actions', ['action' => 'deactivate', 'targets' => array_map(fn (int $id): array => ['id' => (string) $id], $ids)], $headers)->assertOk();
        $seconds = (hrtime(true) - $start) / 1e9;

        $this->assertLessThan(10.0, $seconds, sprintf('bulk deactivation of 100 jobs: %.3f s', $seconds));
        $this->assertCount(100, (array) $response->json('data.results'));
        $this->assertSame(100, $response->json('data.summary.done'));
        $this->assertSame(100, DB::table('restv2_audit_events')->where('event_type', 'syncJobUpdate')->where('outcome', 'success')->count());
    }
}
