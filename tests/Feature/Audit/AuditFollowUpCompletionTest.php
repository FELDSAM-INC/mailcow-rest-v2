<?php

namespace Tests\Feature\Audit;

use App\Audit\AuditEventType;
use App\Models\AuditEvent;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * Feature 002 T019 (research D16): audit records of creations and deletions are written in the
 * change's transaction with their follow-up outcome "pending", and completed exactly once.
 */
class AuditFollowUpCompletionTest extends TestCase
{
    private function logger(): AuditLogger
    {
        return $this->app->make(AuditLogger::class);
    }

    private function pendingCreation(): AuditEvent
    {
        return $this->logger()->record(
            AuditEventType::DomainCreation,
            true,
            ['username' => 'admin', 'role' => 'admin'],
            ['type' => 'domain', 'id' => 'example.org', 'name' => 'example.org'],
            null,
            null,
            ['values' => ['maxMailboxes' => 10], 'templateId' => 1, 'creationReport' => 'pending'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function details(string $id): array
    {
        return json_decode((string) DB::table('restv2_audit_events')->where('id', $id)->value('details'), true);
    }

    public function test_a_pending_outcome_is_completed_once(): void
    {
        $event = $this->pendingCreation();
        $report = ['rateLimit' => 'notRequested', 'dkimKey' => 'created'];

        $this->logger()->completeFollowUps($event->id, 'creationReport', $report);

        $details = $this->details($event->id);
        $this->assertSame($report, $details['creationReport']);
        $this->assertSame(['maxMailboxes' => 10], $details['values']);

        $this->expectException(LogicException::class);
        $this->logger()->completeFollowUps($event->id, 'creationReport', $report);
    }

    public function test_a_string_outcome_is_stored_as_a_string(): void
    {
        $event = $this->logger()->record(AuditEventType::DomainDeletion, true, ['username' => 'admin', 'role' => 'admin'],
            ['type' => 'domain', 'id' => 'example.org', 'name' => 'example.org'], null, null, ['maildirCleanup' => 'pending']);

        $this->logger()->completeFollowUps($event->id, 'maildirCleanup', ['outcome' => 'done']);

        $this->assertSame(['outcome' => 'done'], $this->details($event->id)['maildirCleanup']);
    }

    public function test_only_pending_follow_up_keys_can_be_completed(): void
    {
        $event = $this->pendingCreation();

        foreach (['values', 'maildirCleanup', 'unknownKey'] as $key) {
            try {
                $this->logger()->completeFollowUps($event->id, $key, 'done');
                $this->fail("{$key} was completed");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('pending', $this->details($event->id)['creationReport']);
    }

    public function test_records_stay_immutable_through_the_model(): void
    {
        $event = $this->pendingCreation();

        $this->expectException(LogicException::class);
        $event->forceFill(['outcome' => 'failure'])->save();
    }

    public function test_the_details_whitelist_of_the_new_event_types(): void
    {
        $event = $this->logger()->record(AuditEventType::DomainUpdate, true, ['username' => 'admin', 'role' => 'admin'],
            ['type' => 'domain', 'id' => 'example.org', 'name' => 'example.org'], null, null,
            ['changes' => ['maxMailboxes' => ['old' => 10, 'new' => 20]], 'bulkRequestId' => str_repeat('a', 32), 'secret' => 'x']);

        $this->assertSame(['changes', 'bulkRequestId'], array_keys($this->details($event->id)));

        foreach ([
            AuditEventType::DomainCreation, AuditEventType::DomainUpdate, AuditEventType::DomainDeletion,
            AuditEventType::DomainFooterUpdate, AuditEventType::DomainTemplateCreation,
            AuditEventType::DomainTemplateUpdate, AuditEventType::DomainTemplateDeletion,
        ] as $type) {
            $this->assertContains('problemType', $type->detailKeys());
            $this->assertContains('errors', $type->detailKeys());
        }
    }

    public function test_the_failure_reasons_of_refused_changes_are_stored(): void
    {
        foreach (['attributeNotPermitted', 'versionOutdated', 'validationFailed', 'conflict', 'temporaryFailure'] as $reason) {
            $event = $this->logger()->record(AuditEventType::DomainUpdate, false, ['username' => 'admin', 'role' => 'admin'],
                ['type' => 'domain', 'id' => 'example.org', 'name' => 'example.org'], null, $reason, ['problemType' => 'x']);

            $this->assertSame($reason, DB::table('restv2_audit_events')->where('id', $event->id)->value('failure_reason'));
        }
    }
}
