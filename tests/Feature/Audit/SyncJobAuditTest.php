<?php

namespace Tests\Feature\Audit;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\SyncJobs\SyncJobTestHelpers;
use Tests\TestCase;

/**
 * Feature 008 T061 (FR-025, FR-026, US6 AS-1 to AS-3, SC-007): administrators read a sync job's
 * history with the generic `targetType` and `targetId` filters of `GET /audit-events`: every change
 * and refusal, with actor, time, source address and the changed values, the password only as
 * "changed". Domain administrators and mailbox users may not read the audit log, and no audit row
 * ever holds the remote password.
 */
class SyncJobAuditTest extends TestCase
{
    use ApiKeys;
    use SyncJobTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->syncMailbox('alice@example.org');
        $this->mailcow()->admin('auditor', 'Passw0rd!', 'SSHA256');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function history(string $query): array
    {
        $this->signInAs('auditor', Role::Admin);

        return (array) $this->getJson('/api/v2/audit-events?'.$query)->assertOk()->json('data');
    }

    public function test_a_change_shows_its_actor_time_source_and_values_with_the_password_only_as_changed(): void
    {
        $job = $this->mailcow()->syncJob('alice@example.org');
        $this->asAdmin();
        $this->patchSyncJob($job, ['intervalMinutes' => 30, 'remotePassword' => self::REMOTE_PASSWORD])->assertOk();

        $records = $this->history('targetType=syncJob&targetId='.$job);

        $this->assertCount(1, $records);
        $this->assertSame('syncJobUpdate', $records[0]['eventType']);
        $this->assertSame(['username' => 'admin', 'role' => 'admin', 'apiKeyId' => null], $records[0]['actor']);
        $this->assertSame('127.0.0.1', $records[0]['clientAddress']);
        $this->assertNotEmpty($records[0]['occurredAt']);
        $this->assertSame(['type' => 'syncJob', 'id' => (string) $job, 'name' => 'alice@example.org'], $records[0]['target']);
        $this->assertSame(['old' => 20, 'new' => 30], $records[0]['details']['changes']['intervalMinutes']);
        $this->assertSame('changed', $records[0]['details']['changes']['remotePassword']);
    }

    public function test_a_refused_change_is_listed_by_target_and_by_actor(): void
    {
        $job = $this->mailcow()->syncJob('alice@example.org');
        DB::table('user_acl')->where('username', 'alice@example.org')->update(['syncjobs' => 0]);
        $this->signInAs('alice@example.org', Role::MailboxUser);
        $this->patchSyncJob($job, ['active' => false])->assertForbidden();

        foreach (['targetType=syncJob&targetId='.$job, 'actor=alice@example.org'] as $query) {
            $records = $this->history($query);
            $this->assertCount(1, $records, $query);
            $this->assertSame('failure', $records[0]['outcome']);
            $this->assertSame('permissionDenied', $records[0]['failureReason']);
            $this->assertSame('alice@example.org', $records[0]['target']['name']);
            $this->assertSame('/api/v2/problems/permission-flag-required', $records[0]['details']['problemType']);
        }
    }

    public function test_domain_administrators_and_mailbox_users_may_not_read_the_audit_log(): void
    {
        $this->asDomainAdmin(['example.org']);
        $this->getJson('/api/v2/audit-events?targetType=syncJob')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->asSyncUser('alice@example.org');
        $this->getJson('/api/v2/audit-events?targetType=syncJob')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
    }

    public function test_every_operation_is_recorded_once_per_job_and_bulk_records_share_the_request_id(): void
    {
        $this->asAdmin();
        $id = (int) $this->createSyncJob(self::syncJobBody(['mailbox' => 'alice@example.org']))->assertCreated()->json('data.id');
        $this->patchSyncJob($id, ['intervalMinutes' => 30])->assertOk();
        $this->rescheduleSyncJob($id)->assertOk();
        $second = (int) $this->createSyncJob(self::syncJobBody(['mailbox' => 'alice@example.org', 'remoteUser' => 'second']))->assertCreated()->json('data.id');
        $bulkId = $this->syncJobBulk('deactivate', [$id, $second])->assertOk()->json('data.bulkRequestId');
        $this->deleteSyncJob($id)->assertNoContent();

        $types = array_column($this->history('targetType=syncJob&targetId='.$id.'&order=asc'), 'eventType');
        $this->assertSame(['syncJobCreation', 'syncJobUpdate', 'syncJobReschedule', 'syncJobUpdate', 'syncJobDeletion'], $types);

        $bulk = array_values(array_filter($this->history('targetType=syncJob'), fn (array $r): bool => ($r['details']['bulkRequestId'] ?? null) !== null));
        $this->assertCount(2, $bulk);
        $this->assertSame([$bulkId, $bulkId], array_map(fn (array $r): string => $r['details']['bulkRequestId'], $bulk));
    }

    public function test_no_audit_row_ever_holds_the_remote_password(): void
    {
        $this->asAdmin();
        $id = (int) $this->createSyncJob(self::syncJobBody(['mailbox' => 'alice@example.org']))->json('data.id');
        $this->patchSyncJob($id, ['remotePassword' => self::REMOTE_PASSWORD.'2'])->assertOk();
        $this->patchSyncJob($id, ['remotePassword' => self::REMOTE_PASSWORD, 'exclude' => '('])->assertUnprocessable();
        $this->createSyncJob(self::syncJobBody(['mailbox' => 'alice@example.org']))->assertStatus(409);
        $this->createSyncJob(self::syncJobBody(['mailbox' => 'nobody@example.org']))->assertNotFound();
        $this->rescheduleSyncJob($id)->assertOk();
        $this->syncJobBulk('activate', [$id])->assertOk();
        $this->deleteSyncJob($id)->assertNoContent();
        $this->forgetCookies();
        $this->createSyncJob(self::syncJobBody(['mailbox' => 'alice@example.org']), $this->keyHeader($this->apiKey('admin', 'readOnly')))->assertForbidden();

        $rows = DB::table('restv2_audit_events')->get(['details', 'target_name', 'user_agent']);
        $this->assertGreaterThanOrEqual(9, $rows->count());

        foreach ($rows as $row) {
            $this->assertStringNotContainsString('Remote-S3cret', (string) $row->details.$row->target_name.$row->user_agent);
        }
    }
}
