<?php

namespace Tests\Feature\Fail2Ban;

use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\Support\Redis\MailcowLogs;
use Tests\TestCase;

/**
 * Feature 011 T080 (US5 AS-4, AS-5, AS-6, FR-053, FR-054, research R22): lifting an active ban,
 * allowlisting and denylisting its network.
 */
class Fail2BanBanActionsTest extends TestCase
{
    use MonitoringTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        MailcowLogs::activeBan('203.0.113.0/24', now()->getTimestamp() + 600);
        MailcowLogs::activeBan('2001:db8::/64', now()->getTimestamp() + 600);
        MailcowLogs::f2bList('F2B_WHITELIST', ['192.0.2.0/24']);
        MailcowLogs::f2bList('F2B_BLACKLIST', ['203.0.113.0/24']);
    }

    public function test_a_lift_is_queued(): void
    {
        $this->asAdmin();

        $this->postEmpty('/api/v2/fail2ban/active-bans/203.0.113.0%2F24/lift', $this->csrf())
            ->assertOk()
            ->assertJsonPath('data.action', 'lift')
            ->assertJsonPath('data.ban.network', '203.0.113.0/24')
            ->assertJsonPath('data.ban.queuedForLifting', true)
            ->assertJsonPath('data.banServiceRunning', true);

        $this->assertSame(['203.0.113.0/24' => '1'], MailcowLogs::hash('F2B_QUEUE_UNBAN'));
    }

    public function test_allowlisting_moves_the_network_and_queues_the_lift(): void
    {
        $this->asAdmin();

        $this->postEmpty('/api/v2/fail2ban/active-bans/203.0.113.0%2F24/allowlist', $this->csrf())->assertOk()->assertJsonPath('data.action', 'allowlist');

        $this->assertArrayHasKey('203.0.113.0/24', MailcowLogs::hash('F2B_WHITELIST'));
        $this->assertArrayNotHasKey('203.0.113.0/24', MailcowLogs::hash('F2B_BLACKLIST'));
        $this->assertArrayHasKey('203.0.113.0/24', MailcowLogs::hash('F2B_QUEUE_UNBAN'));
    }

    public function test_denylisting_moves_the_network(): void
    {
        MailcowLogs::f2bList('F2B_WHITELIST', ['2001:db8::/64']);
        $this->asAdmin();

        $this->postEmpty('/api/v2/fail2ban/active-bans/2001%3Adb8%3A%3A%2F64/denylist', $this->csrf())
            ->assertOk()
            ->assertJsonPath('data.ban.network', '2001:db8::/64')
            ->assertJsonPath('data.ban.queuedForLifting', false);

        $this->assertArrayHasKey('2001:db8::/64', MailcowLogs::hash('F2B_BLACKLIST'));
        $this->assertArrayNotHasKey('2001:db8::/64', MailcowLogs::hash('F2B_WHITELIST'));
        $this->assertSame([], MailcowLogs::hash('F2B_QUEUE_UNBAN'));
    }

    public function test_a_network_that_is_not_actively_banned_is_not_found(): void
    {
        $this->asAdmin();

        foreach (['lift', 'allowlist', 'denylist'] as $action) {
            $this->postEmpty("/api/v2/fail2ban/active-bans/198.51.100.0%2F24/{$action}", $this->csrf())->assertNotFound();
        }

        $this->assertSame([], MailcowLogs::hash('F2B_QUEUE_UNBAN'));
    }

    public function test_a_lift_stays_queued_while_the_ban_service_is_stopped(): void
    {
        $this->dockerApi()->setRunning('netfilter-mailcow', false);
        $this->asAdmin();

        $this->postEmpty('/api/v2/fail2ban/active-bans/203.0.113.0%2F24/lift', $this->csrf())->assertOk()->assertJsonPath('data.banServiceRunning', false);
    }

    public function test_a_full_list_refuses_a_new_entry(): void
    {
        config(['restv2.monitoring.ban_list_max_entries' => 2]);
        MailcowLogs::f2bList('F2B_WHITELIST', ['192.0.2.1', '192.0.2.2']);
        $this->asAdmin();

        $this->postEmpty('/api/v2/fail2ban/active-bans/203.0.113.0%2F24/allowlist', $this->csrf())
            ->assertStatus(422)
            ->assertJsonPath('errors.network.0.code', 'tooMany');
        $this->assertArrayNotHasKey('203.0.113.0/24', MailcowLogs::hash('F2B_WHITELIST'));
    }

    public function test_a_reserved_network_cannot_be_denylisted(): void
    {
        MailcowLogs::activeBan('172.22.1.0/24', now()->getTimestamp() + 600);
        $this->asAdmin();

        $this->postEmpty('/api/v2/fail2ban/active-bans/172.22.1.0%2F24/denylist', $this->csrf())
            ->assertStatus(422)
            ->assertJsonPath('errors.network.0.code', 'reserved');
    }
}
