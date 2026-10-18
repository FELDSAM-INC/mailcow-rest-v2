<?php

namespace Tests\Feature\MailQueue;

use App\Audit\AuditEventType;
use App\Clients\DockerApi\DockerCallResult;
use App\Services\Audit\AuditLogger;
use Tests\Support\Audit\FailingAuditProbe;
use Tests\Support\Audit\FlakyAuditLogger;
use Tests\Support\Monitoring\MonitoringFixtures;
use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\TestCase;

/**
 * Feature 011 T065 (US4 AS-3, FR-062, FR-064): one audit record per queue request, listing every
 * ID with its result; queue actions need the audit log before they start.
 */
class MailQueueAuditTest extends TestCase
{
    use MonitoringTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dockerApi()->withQueue(MonitoringFixtures::text('postqueue-j.txt'));
    }

    public function test_a_bulk_request_writes_one_record_with_every_result(): void
    {
        $this->asAdmin();

        $response = $this->postJson('/api/v2/mail-queue/messages/bulk-actions', ['action' => 'delete', 'targets' => [['id' => '9E5F607182'], ['id' => 'DEADBEEF00']]], $this->csrf())->assertOk();

        $record = $this->onlyAuditRecord();
        $this->assertSame('mailQueueDeletion', $record->event_type);
        $this->assertSame('success', $record->outcome);
        $this->assertSame('mailQueue', $record->target_type);
        $this->assertNull($record->target_id);
        $this->assertSame($response->json('data.bulkRequestId'), $record->request_id);
        $this->assertSame([['id' => '9E5F607182', 'outcome' => 'done'], ['id' => 'DEADBEEF00', 'outcome' => 'notFound']], $this->details($record)['results']);
    }

    public function test_a_failed_bulk_request_records_the_failure(): void
    {
        $this->dockerApi()->withExec('postfix-mailcow', 'mailq', 'hold', DockerCallResult::failed('postsuper failed'));
        $this->asAdmin();

        $this->postJson('/api/v2/mail-queue/messages/bulk-actions', ['action' => 'hold', 'targets' => [['id' => '4F0A1C2B3D']]], $this->csrf())->assertStatus(502);

        $record = $this->onlyAuditRecord('mailQueueHold');
        $this->assertSame('serviceFailure', $record->failure_reason);
        $this->assertSame([['id' => '4F0A1C2B3D', 'outcome' => 'failed']], $this->details($record)['results']);
        $this->assertSame('postsuper failed', $this->details($record)['serviceMessage']);
    }

    public function test_flush_and_purge_write_one_record_each(): void
    {
        $this->asAdmin();

        $this->postEmpty('/api/v2/mail-queue/flush', $this->csrf())->assertOk();
        $this->postEmpty('/api/v2/mail-queue/purge', $this->csrf())->assertOk();

        $this->assertSame(['mailQueueFlush', 'mailQueuePurge'], array_column($this->auditRecords(), 'event_type'));
    }

    public function test_an_audit_probe_failure_refuses_without_exec(): void
    {
        FailingAuditProbe::bind($this->app);
        $this->asAdmin();

        $this->postEmpty('/api/v2/mail-queue/purge', $this->csrf())->assertStatus(503);
        $this->postJson('/api/v2/mail-queue/messages/bulk-actions', ['action' => 'delete', 'targets' => [['id' => '9E5F607182']]], $this->csrf())->assertStatus(503);

        $this->dockerApi()->assertNotExecuted('postfix-mailcow', ['cmd' => 'mailq', 'task' => 'super_delete']);
        $this->assertCount(8, $this->dockerApi()->queue());
    }

    public function test_a_record_that_cannot_be_written_after_the_action_is_reported(): void
    {
        $this->app->instance(AuditLogger::class, new FlakyAuditLogger(failingTypes: [AuditEventType::MailQueuePurge, AuditEventType::MailQueueHold]));
        $this->sleeper()->advancing($this->clock());
        $this->asAdmin();

        $this->postEmpty('/api/v2/mail-queue/purge', $this->csrf())->assertOk()->assertHeader('Audit-Recorded', 'false');
        $this->dockerApi()->withQueue(MonitoringFixtures::text('postqueue-j.txt'));
        $this->postJson('/api/v2/mail-queue/messages/bulk-actions', ['action' => 'hold', 'targets' => [['id' => '4F0A1C2B3D']]], $this->csrf())
            ->assertOk()
            ->assertHeader('Audit-Recorded', 'false');
    }
}
