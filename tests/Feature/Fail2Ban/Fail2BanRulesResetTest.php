<?php

namespace Tests\Feature\Fail2Ban;

use Tests\Support\Audit\FailingAuditProbe;
use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\Support\Redis\MailcowLogs;
use Tests\TestCase;

/**
 * Feature 011 T078 (US5 AS-8, FR-051, FR-064, research R21): resetting the ban rules removes them,
 * restarts the ban service and waits up to 10 seconds for its defaults.
 */
class Fail2BanRulesResetTest extends TestCase
{
    use MonitoringTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        MailcowLogs::f2bRegex(['custom (\d+)']);
        $this->sleeper()->advancing($this->clock());
    }

    public function test_the_defaults_restored_by_the_restarted_ban_service_are_returned(): void
    {
        $this->dockerApi()->onRestart('netfilter-mailcow', fn () => MailcowLogs::f2bRegex(MailcowLogs::defaultRules()));
        $this->asAdmin();

        $response = $this->postEmpty('/api/v2/fail2ban/rules/reset', $this->csrf())->assertOk();

        $this->assertCount(9, $response->json('data.rules'));
        $response->assertHeader('ETag', (string) $response->json('data.etag'));
        $this->dockerApi()->assertRestarted('netfilter-mailcow');
        $record = $this->onlyAuditRecord('fail2banRulesReset');
        $this->assertSame(['custom (\d+)'], $this->details($record)['oldRules']);
        $this->assertCount(9, $this->details($record)['newRules']);
    }

    public function test_defaults_that_do_not_appear_in_time(): void
    {
        $this->asAdmin();

        $this->postEmpty('/api/v2/fail2ban/rules/reset', $this->csrf())
            ->assertStatus(504)
            ->assertJsonPath('type', '/api/v2/problems/ban-rules-not-restored');

        $this->assertNull(MailcowLogs::get('F2B_REGEX'), 'the stored rules were removed');
        $this->assertSame(array_fill(0, 10, 1.0), $this->sleeper()->slept);
        $record = $this->onlyAuditRecord('fail2banRulesReset');
        $this->assertSame('rulesNotRestored', $record->failure_reason);
        $this->assertNull($this->details($record)['newRules']);
    }

    public function test_an_unavailable_container_service_keeps_the_rules(): void
    {
        $this->dockerApi()->failNext('containers', 'unreachable');
        $this->asAdmin();

        $this->postEmpty('/api/v2/fail2ban/rules/reset', $this->csrf())->assertStatus(503);

        $this->assertSame('{"1": "custom (\\\\d+)"}', MailcowLogs::get('F2B_REGEX'));
        $this->dockerApi()->assertNotRestarted();
    }

    public function test_a_restart_timeout_or_unreachable_service_after_the_removal_has_an_unknown_outcome(): void
    {
        $this->dockerApi()->failNext('restart', 'timeout');
        $this->asAdmin();
        $this->postEmpty('/api/v2/fail2ban/rules/reset', $this->csrf())->assertStatus(504)->assertJsonPath('type', '/api/v2/problems/outcome-unknown')->assertJsonPath('step', 'rulesReset');

        MailcowLogs::f2bRegex(['custom (\d+)']);
        $this->dockerApi()->failNext('restart', 'unreachable');
        $this->postEmpty('/api/v2/fail2ban/rules/reset', $this->csrf())->assertStatus(504)->assertJsonPath('type', '/api/v2/problems/outcome-unknown');

        $this->assertSame(['outcomeUnknown', 'outcomeUnknown'], array_column($this->auditRecords('fail2banRulesReset'), 'failure_reason'));
    }

    public function test_a_failed_restart_is_a_container_action_failure(): void
    {
        $this->dockerApi()->failNext('restart', 'danger:409 Client Error');
        $this->asAdmin();

        $this->postEmpty('/api/v2/fail2ban/rules/reset', $this->csrf())
            ->assertStatus(502)
            ->assertJsonPath('type', '/api/v2/problems/container-action-failed')
            ->assertJsonPath('container', 'netfilter-mailcow');
    }

    public function test_an_audit_probe_failure_deletes_nothing(): void
    {
        FailingAuditProbe::bind($this->app);
        $this->asAdmin();

        $this->postEmpty('/api/v2/fail2ban/rules/reset', $this->csrf())->assertStatus(503);

        $this->assertNotNull(MailcowLogs::get('F2B_REGEX'));
        $this->dockerApi()->assertNotRestarted();
    }

    public function test_an_outdated_tag_is_refused(): void
    {
        $this->asAdmin();

        $this->postEmpty('/api/v2/fail2ban/rules/reset', $this->csrf() + ['If-Match' => '"outdated"'])->assertStatus(412);
        $this->assertNotNull(MailcowLogs::get('F2B_REGEX'));
    }
}
