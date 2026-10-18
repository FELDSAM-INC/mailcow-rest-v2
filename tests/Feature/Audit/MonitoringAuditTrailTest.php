<?php

namespace Tests\Feature\Audit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Monitoring\MonitoringFixtures;
use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\Support\Redis\MailcowLogs;
use Tests\TestCase;

/**
 * Feature 011 T105 (US8 AS-1 to AS-3, FR-062, FR-063, SC-006): every state-changing request and
 * every content view writes exactly one record, other reads none, and the generic audit filters
 * find the records by target.
 */
class MonitoringAuditTrailTest extends TestCase
{
    use MonitoringTestHelpers;

    private const BAN_LIST_ID = '1b6c4e1e-7b0f-4c9e-9a65-0f6a6c1c8f11';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dockerApi()->withQueue(MonitoringFixtures::text('postqueue-j.txt'));
        $this->dockerApi()->onRestart('netfilter-mailcow', fn () => MailcowLogs::f2bRegex(MailcowLogs::defaultRules()));
        MailcowLogs::netfilterDefaults(self::BAN_LIST_ID);
        MailcowLogs::f2bRegex(['custom (\d+)']);
        MailcowLogs::activeBan('203.0.113.0/24', time() + 600);
        MailcowLogs::activeBan('198.51.100.0/24', time() + 600);
        MailcowLogs::activeBan('192.0.2.0/24', time() + 600);
        $this->sleeper()->advancing($this->clock());
    }

    private function recordCount(): int
    {
        return DB::table('restv2_audit_events')->count();
    }

    /**
     * Runs a request and asserts how many records it wrote.
     */
    private function expectRecords(int $records, callable $request): void
    {
        $before = $this->recordCount();
        $request();
        $this->assertSame($before + $records, $this->recordCount());
    }

    public function test_one_record_per_state_change_and_content_view_and_none_for_reads(): void
    {
        $this->asAdmin();
        $csrf = $this->csrf();

        // Successful changes.
        $this->expectRecords(1, fn () => $this->postEmpty('/api/v2/containers/clamd-mailcow/stop', $csrf)->assertOk());
        $this->expectRecords(1, fn () => $this->postEmpty('/api/v2/containers/clamd-mailcow/start', $csrf)->assertOk());
        $this->expectRecords(1, fn () => $this->postEmpty('/api/v2/containers/sogo-mailcow/restart', $csrf)->assertOk());
        $this->expectRecords(1, fn () => $this->postJson('/api/v2/mail-queue/messages/bulk-actions', ['action' => 'hold', 'targets' => [['id' => '4F0A1C2B3D'], ['id' => '5A1B2C3D4E']]], $csrf)->assertOk());
        $this->expectRecords(1, fn () => $this->getJson('/api/v2/mail-queue/messages/4F0A1C2B3D/content')->assertOk());
        $this->expectRecords(1, fn () => $this->postEmpty('/api/v2/mail-queue/flush', $csrf)->assertOk());
        $this->expectRecords(1, fn () => $this->patchMerge('/api/v2/fail2ban/settings', ['banTime' => 3600, 'allowlist' => ['10.0.0.0/8']], $csrf)->assertOk());
        $this->expectRecords(1, fn () => $this->putJson('/api/v2/fail2ban/rules', ['rules' => ['new (\d+)']], $csrf)->assertOk());
        $this->expectRecords(1, fn () => $this->postEmpty('/api/v2/fail2ban/rules/reset', $csrf)->assertOk());
        $this->expectRecords(1, fn () => $this->postEmpty('/api/v2/fail2ban/active-bans/203.0.113.0%2F24/lift', $csrf)->assertOk());
        $this->expectRecords(1, fn () => $this->postEmpty('/api/v2/fail2ban/active-bans/198.51.100.0%2F24/allowlist', $csrf)->assertOk());
        $this->expectRecords(1, fn () => $this->postEmpty('/api/v2/fail2ban/active-bans/192.0.2.0%2F24/denylist', $csrf)->assertOk());
        $this->expectRecords(1, fn () => $this->postEmpty('/api/v2/fail2ban/ban-list-rotation', $csrf)->assertOk());
        $this->expectRecords(1, fn () => $this->postEmpty('/api/v2/mail-queue/purge', $csrf)->assertOk());

        // Refused changes.
        $this->expectRecords(1, fn () => $this->postEmpty('/api/v2/containers/mysql-mailcow/stop', $csrf)->assertStatus(409));
        $this->expectRecords(1, fn () => $this->patchMerge('/api/v2/fail2ban/settings', ['banTime' => 3601], $csrf + ['If-Match' => '"outdated"'])->assertStatus(412));
        $this->expectRecords(1, fn () => $this->postEmpty('/api/v2/fail2ban/active-bans/203.0.113.99%2F32/lift', $csrf)->assertNotFound());
        $this->expectRecords(1, fn () => $this->putJson('/api/v2/fail2ban/rules', ['rules' => ['no group']], $csrf)->assertStatus(422));
        $this->dockerApi()->setRunning('postfix-mailcow', false);
        $this->getJson('/api/v2/containers')->assertOk();
        $this->expectRecords(1, fn () => $this->postEmpty('/api/v2/mail-queue/flush', $csrf)->assertStatus(409));

        // Reads write nothing.
        foreach (['/api/v2/host', '/api/v2/containers', '/api/v2/logs/postfix', '/api/v2/fail2ban/settings', '/api/v2/fail2ban/rules', '/api/v2/fail2ban/active-bans', '/api/v2/rspamd/statistics', '/api/v2/capabilities/monitoring'] as $path) {
            $this->expectRecords(0, fn () => $this->getJson($path)->assertOk());
        }
    }

    public function test_refusals_of_keys_and_domain_administrators_are_recorded(): void
    {
        $this->expectRecords(1, fn () => $this->postEmpty('/api/v2/containers/sogo-mailcow/restart', $this->adminKey('readOnly'))->assertStatus(403));

        $this->asDomainAdmin();
        $this->expectRecords(1, fn () => $this->postEmpty('/api/v2/mail-queue/purge', $this->csrf())->assertStatus(403));
        $this->expectRecords(0, fn () => $this->getJson('/api/v2/host')->assertStatus(403));
    }

    public function test_the_audit_log_filters_find_the_records_by_target(): void
    {
        $this->asAdmin();
        $csrf = $this->csrf();
        $this->postEmpty('/api/v2/containers/clamd-mailcow/stop', $csrf)->assertOk();
        $this->postJson('/api/v2/mail-queue/messages/bulk-actions', ['action' => 'delete', 'targets' => [['id' => '9E5F607182']]], $csrf)->assertOk();
        $this->getJson('/api/v2/mail-queue/messages/4F0A1C2B3D/content')->assertOk();
        $this->patchMerge('/api/v2/fail2ban/settings', ['banTime' => 3600], $csrf)->assertOk();
        $this->putJson('/api/v2/fail2ban/rules', ['rules' => ['new (\d+)']], $csrf)->assertOk();
        $this->postEmpty('/api/v2/fail2ban/active-bans/203.0.113.0%2F24/lift', $csrf)->assertOk();
        $this->postEmpty('/api/v2/fail2ban/ban-list-rotation', $csrf)->assertOk();

        $container = $this->getJson('/api/v2/audit-events?targetType=container&targetId=clamd-mailcow')->assertOk();
        $container->assertJsonPath('meta.total', 1);
        $entry = $container->json('data.0');
        $this->assertSame('containerStop', $entry['eventType']);
        $this->assertSame('success', $entry['outcome']);
        $this->assertSame('admin', $entry['actor']['username']);
        $this->assertSame('127.0.0.1', $entry['clientAddress']);
        $this->assertSame(['outcome' => 'done', 'stateBefore' => 'running', 'stateAfter' => 'exited'], $entry['details']);
        $this->assertNotEmpty($entry['occurredAt']);

        foreach (['mailQueue' => 'mailQueueDeletion', 'mailQueueMessage' => 'mailQueueMessageView', 'fail2banSettings' => 'fail2banSettingsUpdate', 'fail2banRules' => 'fail2banRulesReplacement', 'fail2banActiveBan' => 'fail2banBanLift', 'fail2banBanListId' => 'fail2banBanListIdRotation'] as $type => $event) {
            $this->getJson("/api/v2/audit-events?targetType={$type}")->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.eventType', $event);
        }

        $this->getJson('/api/v2/audit-events?targetType=fail2banActiveBan&targetId=203.0.113.0/24')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_no_record_contains_content_ban_list_ids_or_secrets(): void
    {
        $this->asAdmin();
        $csrf = $this->csrf();
        $this->getJson('/api/v2/mail-queue/messages/4F0A1C2B3D/content')->assertOk();
        $this->postEmpty('/api/v2/fail2ban/ban-list-rotation', $csrf)->assertOk();
        $this->postEmpty('/api/v2/containers/sogo-mailcow/restart', $csrf)->assertOk();
        $newId = json_decode((string) Redis::connection('mailcow')->command('get', ['F2B_OPTIONS']), true)['banlist_id'];

        $dump = (string) json_encode(DB::table('restv2_audit_events')->get());

        $this->assertStringNotContainsString('confidential quarterly', $dump);
        $this->assertStringNotContainsString(self::BAN_LIST_ID, $dump);
        $this->assertStringNotContainsString((string) $newId, $dump);

        foreach (MonitoringFixtures::PLANTED_SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
    }
}
