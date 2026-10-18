<?php

namespace Tests\Feature\Fail2Ban;

use Illuminate\Support\Facades\Redis;
use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\Support\Redis\MailcowLogs;
use Tests\TestCase;

/**
 * Feature 011 T074 (FR-044, FR-056, research R18, R23): reading the ban service settings.
 */
class Fail2BanSettingsReadTest extends TestCase
{
    use MonitoringTestHelpers;

    public function test_nothing_stored_reports_the_ban_service_defaults(): void
    {
        $this->asAdmin();

        $response = $this->getJson('/api/v2/fail2ban/settings')->assertOk();

        $this->assertSame([
            'banTime' => 1800, 'maxBanTime' => 10000, 'banTimeIncrement' => true, 'maxAttempts' => 10,
            'retryWindow' => 600, 'ipv4PrefixLength' => 32, 'ipv6PrefixLength' => 128, 'externallyEnforced' => false,
            'allowlist' => [], 'denylist' => [], 'banListId' => null, 'banListUrl' => null, 'stored' => false,
            'banServiceRunning' => true,
        ], array_diff_key((array) $response->json('data'), ['etag' => true]));
        $response->assertHeader('ETag', (string) $response->json('data.etag'));
    }

    public function test_the_old_separate_keys_are_read_like_the_ban_service(): void
    {
        Redis::connection('mailcow')->command('set', ['F2B_BAN_TIME', '3600']);
        $this->asAdmin();

        $this->getJson('/api/v2/fail2ban/settings')->assertOk()->assertJsonPath('data.banTime', 3600)->assertJsonPath('data.stored', false);
    }

    public function test_stored_settings_map_to_the_contract(): void
    {
        MailcowLogs::f2bOptions(['ban_time' => 900, 'ban_time_increment' => false, 'max_ban_time' => 86400, 'netban_ipv4' => 24, 'netban_ipv6' => 64, 'max_attempts' => 5, 'retry_window' => 300, 'banlist_id' => '0b2f8e3c-5a6d-4e7f-8a9b-0c1d2e3f4a5b', 'manage_external' => 1]);
        MailcowLogs::f2bList('F2B_WHITELIST', ['mail.example.org', '10.0.0.10', '10.0.0.9', '192.0.2.0/24']);
        MailcowLogs::f2bList('F2B_BLACKLIST', ['203.0.113.0/24']);
        $this->asAdmin();

        $data = $this->getJson('/api/v2/fail2ban/settings')->assertOk()->json('data');

        $this->assertSame(900, $data['banTime']);
        $this->assertFalse($data['banTimeIncrement']);
        $this->assertSame(86400, $data['maxBanTime']);
        $this->assertSame(24, $data['ipv4PrefixLength']);
        $this->assertSame(64, $data['ipv6PrefixLength']);
        $this->assertSame(5, $data['maxAttempts']);
        $this->assertSame(300, $data['retryWindow']);
        $this->assertTrue($data['externallyEnforced']);
        $this->assertTrue($data['stored']);
        $this->assertSame(['10.0.0.9', '10.0.0.10', '192.0.2.0/24', 'mail.example.org'], $data['allowlist'], 'sorted naturally');
        $this->assertSame(['203.0.113.0/24'], $data['denylist']);
        $this->assertSame('0b2f8e3c-5a6d-4e7f-8a9b-0c1d2e3f4a5b', $data['banListId']);
        $this->assertSame('https://mail.example.org/f2b-banlist?id=0b2f8e3c-5a6d-4e7f-8a9b-0c1d2e3f4a5b', $data['banListUrl']);
    }

    public function test_the_ban_service_indicator(): void
    {
        $this->asAdmin();
        $this->dockerApi()->setRunning('netfilter-mailcow', false);
        $this->getJson('/api/v2/fail2ban/settings')->assertOk()->assertJsonPath('data.banServiceRunning', false);
    }

    public function test_the_settings_read_succeeds_without_the_container_service(): void
    {
        $this->dockerApi()->failAlways('containers', 'unreachable');
        $this->asAdmin();

        $this->getJson('/api/v2/fail2ban/settings')->assertOk()->assertJsonPath('data.banServiceRunning', null);
    }
}
