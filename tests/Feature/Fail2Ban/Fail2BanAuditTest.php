<?php

namespace Tests\Feature\Fail2Ban;

use App\Audit\AuditEventType;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Audit\FlakyAuditLogger;
use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\Support\Redis\MailcowLogs;
use Tests\Support\Redis\UnavailableSharedStore;
use Tests\TestCase;

/**
 * Feature 011 T082 (FR-062, US8 AS-2, AS-3): the audit records of the ban service changes.
 */
class Fail2BanAuditTest extends TestCase
{
    use MonitoringTestHelpers;

    private const ID = '1b6c4e1e-7b0f-4c9e-9a65-0f6a6c1c8f11';

    protected function setUp(): void
    {
        parent::setUp();

        MailcowLogs::netfilterDefaults(self::ID);
        MailcowLogs::f2bList('F2B_WHITELIST', ['192.0.2.0/24', '198.51.100.1']);
    }

    public function test_a_settings_change_records_old_and_new_values_and_list_changes(): void
    {
        $this->asAdmin();

        $this->patchMerge('/api/v2/fail2ban/settings', ['banTime' => 3600, 'allowlist' => ['192.0.2.0/24', '10.0.0.0/8']], $this->csrf())->assertOk();

        $record = $this->onlyAuditRecord('fail2banSettingsUpdate');
        $this->assertSame('fail2banSettings', $record->target_type);
        $this->assertSame([
            'changes' => ['banTime' => ['old' => 1800, 'new' => 3600]],
            'allowlistAdded' => ['10.0.0.0/8'],
            'allowlistRemoved' => ['198.51.100.1'],
        ], $this->details($record));
        $this->assertStringNotContainsString(self::ID, json_encode($record) ?: '');
    }

    public function test_the_first_save_records_no_ban_list_id(): void
    {
        Redis::connection('mailcow')->command('del', ['F2B_OPTIONS']);
        $this->asAdmin();

        $this->patchMerge('/api/v2/fail2ban/settings', ['banTime' => 3600], $this->csrf())->assertOk();

        $details = $this->details($this->onlyAuditRecord('fail2banSettingsUpdate'));
        $this->assertArrayNotHasKey('banListId', $details['changes']);
        $this->assertSame(['banTime' => ['old' => 1800, 'new' => 3600]], $details['changes']);
    }

    public function test_rules_replacement_records_old_and_new_rules(): void
    {
        MailcowLogs::f2bRegex(['old (\d+)']);
        $this->asAdmin();

        $this->putJson('/api/v2/fail2ban/rules', ['rules' => ['new (\d+)']], $this->csrf())->assertOk();

        $this->assertSame(['oldRules' => ['old (\d+)'], 'newRules' => ['new (\d+)']], $this->details($this->onlyAuditRecord('fail2banRulesReplacement')));
    }

    public function test_ban_actions_record_the_network_and_list_changes(): void
    {
        MailcowLogs::activeBan('203.0.113.0/24', now()->getTimestamp() + 600);
        MailcowLogs::f2bList('F2B_BLACKLIST', ['203.0.113.0/24']);
        $this->asAdmin();

        $this->postEmpty('/api/v2/fail2ban/active-bans/203.0.113.0%2F24/allowlist', $this->csrf())->assertOk();

        $record = $this->onlyAuditRecord('fail2banBanAllowlisting');
        $this->assertSame('fail2banActiveBan', $record->target_type);
        $this->assertSame('203.0.113.0/24', $record->target_id);
        $this->assertSame(['network' => '203.0.113.0/24', 'allowlistAdded' => ['203.0.113.0/24'], 'denylistRemoved' => ['203.0.113.0/24']], $this->details($record));
    }

    public function test_a_rotation_records_no_id(): void
    {
        $this->asAdmin();

        $id = (string) $this->postEmpty('/api/v2/fail2ban/ban-list-rotation', $this->csrf())->assertOk()->json('data.banListId');

        $record = $this->onlyAuditRecord('fail2banBanListIdRotation');
        $this->assertSame([], $this->details($record));
        $this->assertSame('fail2banBanListId', $record->target_type);
        $this->assertStringNotContainsString($id, json_encode($record) ?: '');
        $this->assertStringNotContainsString(self::ID, json_encode($record) ?: '');
    }

    public function test_an_outdated_tag_and_an_unavailable_store_are_recorded(): void
    {
        $this->asAdmin();
        $this->patchMerge('/api/v2/fail2ban/settings', ['banTime' => 3600], $this->csrf() + ['If-Match' => '"outdated"'])->assertStatus(412);
        $this->assertSame('versionOutdated', $this->onlyAuditRecord('fail2banSettingsUpdate')->failure_reason);

        UnavailableSharedStore::bind($this->app);
        $this->postEmpty('/api/v2/fail2ban/ban-list-rotation', $this->csrf())->assertStatus(503);
        $this->assertSame('temporaryFailure', $this->onlyAuditRecord('fail2banBanListIdRotation')->failure_reason);
    }

    public function test_a_change_whose_record_cannot_be_written_is_not_stored(): void
    {
        $this->app->instance(AuditLogger::class, new FlakyAuditLogger(failingTypes: [AuditEventType::Fail2banSettingsUpdate]));
        $this->asAdmin();

        $this->patchMerge('/api/v2/fail2ban/settings', ['banTime' => 3600], $this->csrf())->assertStatus(503)->assertJsonPath('type', '/api/v2/problems/temporary-failure');

        $this->assertStringContainsString('"ban_time": 1800', (string) MailcowLogs::get('F2B_OPTIONS'));
    }
}
