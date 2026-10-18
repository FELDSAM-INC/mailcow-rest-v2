<?php

namespace Tests\Feature\MailQueue;

use App\Audit\AuditEventType;
use App\Auth\Role;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Tests\Support\Audit\FlakyAuditLogger;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\Support\Monitoring\MonitoringFixtures;
use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\TestCase;

/**
 * Feature 011 T062 (US4 AS-2, FR-039, FR-062): viewing a queued message; every view is audited
 * with the queue ID, never the content.
 */
class MailQueueContentTest extends TestCase
{
    use MonitoringTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dockerApi()->withQueue(MonitoringFixtures::text('postqueue-j.txt'));
    }

    public function test_the_content_is_returned_and_the_view_audited(): void
    {
        $this->asAdmin();

        $response = $this->getJson('/api/v2/mail-queue/messages/4F0A1C2B3D/content')->assertOk();

        $response->assertJsonPath('data.id', '4F0A1C2B3D');
        $this->assertStringStartsWith('*** ENVELOPE RECORDS deferred/4F0A1C2B3D ***', (string) $response->json('data.content'));
        $this->dockerApi()->assertExecuted('postfix-mailcow', ['cmd' => 'mailq', 'task' => 'cat', 'items' => ['4F0A1C2B3D']]);

        $record = $this->onlyAuditRecord();
        $this->assertSame('mailQueueMessageView', $record->event_type);
        $this->assertSame('success', $record->outcome);
        $this->assertSame('mailQueueMessage', $record->target_type);
        $this->assertSame('4F0A1C2B3D', $record->target_id);
        $this->assertStringNotContainsString('confidential quarterly', (string) json_encode(DB::table('restv2_audit_events')->get()));
    }

    public function test_a_message_that_left_the_queue_is_not_found_and_audited(): void
    {
        $this->asAdmin();

        $this->getJson('/api/v2/mail-queue/messages/ABCDEF0123/content')->assertNotFound();

        $record = $this->onlyAuditRecord('mailQueueMessageView');
        $this->assertSame('notFound', $record->failure_reason);
        $this->assertSame('ABCDEF0123', $record->target_id);
    }

    public function test_a_read_only_administrator_key_may_view_and_is_audited(): void
    {
        $key = $this->apiKey('admin', 'readOnly');
        $this->mailcow()->admin('admin', MailcowFactory::PASSWORD);

        $this->getJson('/api/v2/mail-queue/messages/4F0A1C2B3D/content', $this->keyHeader($key))->assertOk();

        $this->assertSame($key['id'], $this->onlyAuditRecord('mailQueueMessageView')->actor_api_key_id);
    }

    public function test_the_content_is_not_returned_when_the_view_cannot_be_audited(): void
    {
        $this->app->instance(AuditLogger::class, new FlakyAuditLogger(failingTypes: [AuditEventType::MailQueueMessageView]));
        $this->asAdmin();

        $response = $this->getJson('/api/v2/mail-queue/messages/4F0A1C2B3D/content')->assertStatus(503);

        $this->assertStringNotContainsString('ENVELOPE', (string) $response->getContent());
    }

    public function test_an_invalid_queue_id_matches_no_route_and_is_not_audited(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $this->getJson('/api/v2/mail-queue/messages/XYZ/content')->assertNotFound();
        $this->assertSame([], $this->auditRecords());
    }

    public function test_refused_views_are_audited(): void
    {
        $this->mailcow()->admin('restricted', MailcowFactory::PASSWORD, attributes: ['force_pw_update' => '1']);
        $this->signInAs('restricted', Role::Admin);
        $this->getJson('/api/v2/mail-queue/messages/4F0A1C2B3D/content')->assertStatus(403);

        $this->forgetCookies();
        $this->asDomainAdmin();
        $this->getJson('/api/v2/mail-queue/messages/4F0A1C2B3D/content')->assertStatus(403);

        $records = $this->auditRecords('mailQueueMessageView');
        $this->assertCount(2, $records);
        $this->assertSame(['permissionDenied', 'permissionDenied'], array_column($records, 'failure_reason'));
        $this->assertSame(['mailQueueMessage', 'mailQueueMessage'], array_column($records, 'target_type'));
    }

    public function test_a_stopped_postfix_container_is_refused(): void
    {
        $this->dockerApi()->setRunning('postfix-mailcow', false);
        $this->asAdmin();

        $this->getJson('/api/v2/mail-queue/messages/4F0A1C2B3D/content')->assertStatus(409)->assertJsonPath('container', 'postfix-mailcow');
    }
}
