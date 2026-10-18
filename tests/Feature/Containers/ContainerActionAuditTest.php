<?php

namespace Tests\Feature\Containers;

use App\Audit\AuditEventType;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Log;
use Tests\Support\Audit\FailingAuditProbe;
use Tests\Support\Audit\FlakyAuditLogger;
use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\TestCase;

/**
 * Feature 011 T040 (US2 AS-6, AS-7, FR-062, FR-064, research R24): exactly one audit record per
 * container action, whatever its outcome, with the states before and after; irreversible actions
 * need the audit log before they start.
 */
class ContainerActionAuditTest extends TestCase
{
    use MonitoringTestHelpers;

    public function test_a_done_restart_is_recorded_with_its_states(): void
    {
        $this->asAdmin();
        $this->postEmpty('/api/v2/containers/sogo-mailcow/restart', $this->csrf())->assertOk();

        $record = $this->onlyAuditRecord();
        $this->assertSame('containerRestart', $record->event_type);
        $this->assertSame('success', $record->outcome);
        $this->assertSame('container', $record->target_type);
        $this->assertSame('sogo-mailcow', $record->target_id);
        $this->assertSame('admin', $record->actor_username);
        $this->assertSame(['outcome' => 'done', 'stateBefore' => 'exited', 'stateAfter' => 'running'], $this->details($record));
    }

    public function test_already_in_state_is_recorded(): void
    {
        $this->asAdmin();
        $this->postEmpty('/api/v2/containers/postfix-mailcow/start', $this->csrf())->assertOk();

        $record = $this->onlyAuditRecord('containerStart');
        $this->assertSame(['outcome' => 'alreadyInState', 'stateBefore' => 'running', 'stateAfter' => 'running'], $this->details($record));
    }

    public function test_a_protected_refusal_is_recorded_as_a_conflict(): void
    {
        $this->asAdmin();
        $this->postEmpty('/api/v2/containers/mysql-mailcow/stop', $this->csrf())->assertStatus(409);

        $record = $this->onlyAuditRecord('containerStop');
        $this->assertSame('failure', $record->outcome);
        $this->assertSame('conflict', $record->failure_reason);
        $details = $this->details($record);
        $this->assertSame('/api/v2/problems/container-protected', $details['problemType']);
        $this->assertSame('running', $details['stateBefore']);
        $this->assertSame('running', $details['stateAfter']);
    }

    public function test_a_failed_start_is_recorded_as_a_service_failure(): void
    {
        $this->dockerApi()->resistActions('sogo-mailcow', 10);
        $this->asAdmin();
        $this->postEmpty('/api/v2/containers/sogo-mailcow/start', $this->csrf())->assertStatus(502);

        $record = $this->onlyAuditRecord('containerStart');
        $this->assertSame('serviceFailure', $record->failure_reason);
        $details = $this->details($record);
        $this->assertSame('exited', $details['stateBefore']);
        $this->assertSame('exited', $details['stateAfter']);
        $this->assertSame('The container did not start.', $details['serviceMessage']);
    }

    public function test_an_unknown_outcome_is_recorded(): void
    {
        $this->dockerApi()->failNext('restart', 'timeout');
        $this->asAdmin();
        $this->postEmpty('/api/v2/containers/sogo-mailcow/restart', $this->csrf())->assertStatus(504);

        $record = $this->onlyAuditRecord('containerRestart');
        $this->assertSame('outcomeUnknown', $record->failure_reason);
        $this->assertSame('restart', $this->details($record)['step']);
        $this->assertSame('exited', $this->details($record)['stateBefore']);
    }

    public function test_an_audit_probe_failure_refuses_the_action(): void
    {
        FailingAuditProbe::bind($this->app);
        $this->asAdmin();

        $this->postEmpty('/api/v2/containers/sogo-mailcow/restart', $this->csrf())
            ->assertStatus(503)
            ->assertJsonPath('type', '/api/v2/problems/temporary-failure');
        $this->dockerApi()->assertNoAction();
    }

    public function test_a_record_that_cannot_be_written_after_the_action_is_reported(): void
    {
        $this->app->instance(AuditLogger::class, new FlakyAuditLogger(failingTypes: [AuditEventType::ContainerRestart]));
        $this->sleeper()->advancing($this->clock());
        Log::spy();
        $this->asAdmin();

        $this->postEmpty('/api/v2/containers/mysql-mailcow/restart', $this->csrf())
            ->assertOk()
            ->assertHeader('Audit-Recorded', 'false');

        $this->assertSame([], $this->auditRecords());
        Log::getFacadeRoot()->shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'containerRestart'))->once();
    }

    public function test_a_read_only_key_is_refused_and_recorded(): void
    {
        $this->postEmpty('/api/v2/containers/sogo-mailcow/restart', $this->adminKey('readOnly'))
            ->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');

        $record = $this->onlyAuditRecord('containerRestart');
        $this->assertSame('permissionDenied', $record->failure_reason);
        $this->assertSame('sogo-mailcow', $record->target_id);
        $this->assertNotNull($record->actor_api_key_id);
        $this->assertSame('/api/v2/problems/insufficient-key-scope', $this->details($record)['problemType']);
        $this->dockerApi()->assertNothingCalled();
    }

    public function test_a_domain_administrator_is_refused_and_recorded(): void
    {
        $this->asDomainAdmin();

        $this->postEmpty('/api/v2/containers/sogo-mailcow/stop', $this->csrf())->assertStatus(403);

        $this->assertSame('permissionDenied', $this->onlyAuditRecord('containerStop')->failure_reason);
    }

    public function test_an_unavailable_container_service_is_recorded_as_a_temporary_failure(): void
    {
        $this->dockerApi()->failNext('containers', 'unreachable');
        $this->asAdmin();

        $this->postEmpty('/api/v2/containers/sogo-mailcow/start', $this->csrf())->assertStatus(503);

        $this->assertSame('temporaryFailure', $this->onlyAuditRecord('containerStart')->failure_reason);
    }

    public function test_an_unknown_container_is_recorded_as_not_found(): void
    {
        $this->asAdmin();

        $this->postEmpty('/api/v2/containers/unknown-mailcow/restart', $this->csrf())->assertNotFound();

        $record = $this->onlyAuditRecord('containerRestart');
        $this->assertSame('notFound', $record->failure_reason);
        $this->assertSame('unknown-mailcow', $record->target_id);
    }
}
