<?php

namespace Tests\Feature\Fail2Ban;

use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\Support\Redis\MailcowLogs;
use Tests\TestCase;

/**
 * Feature 011 T079 (FR-052): the active and the permanent bans.
 */
class Fail2BanBanListsTest extends TestCase
{
    use MonitoringTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeSecond();
        $now = now()->getTimestamp();
        MailcowLogs::activeBan('203.0.113.0/24', $now + 600);
        MailcowLogs::activeBan('198.51.100.7/32', $now + 60);
        MailcowLogs::activeBan('2001:db8::/64', $now + 1800);
        MailcowLogs::activeBan('198.51.100.0/24', $now + 600);
        MailcowLogs::queuedUnban('203.0.113.0/24');
        MailcowLogs::permanentBan('192.0.2.66/32', $now - 3600);
        MailcowLogs::permanentBan('2001:db8:1::/48', $now - 60);
    }

    public function test_active_bans_soonest_ending_first(): void
    {
        $this->asAdmin();

        $data = (array) $this->getJson('/api/v2/fail2ban/active-bans')->assertOk()->json('data');

        $this->assertSame(['198.51.100.7/32', '198.51.100.0/24', '203.0.113.0/24', '2001:db8::/64'], array_column($data, 'network'));
        $this->assertSame('198.51.100.7', $data[0]['address']);
        $this->assertSame(60, $data[0]['remainingSeconds']);
        $this->assertSame(now()->addSeconds(60)->utc()->format('Y-m-d\TH:i:s\Z'), $data[0]['endsAt']);
        $this->assertTrue($data[2]['queuedForLifting']);
        $this->assertFalse($data[0]['queuedForLifting']);
    }

    public function test_sorting_by_network_search_filter_and_paging(): void
    {
        $this->asAdmin();

        $this->assertSame(['198.51.100.0/24', '198.51.100.7/32', '203.0.113.0/24', '2001:db8::/64'], array_column((array) $this->getJson('/api/v2/fail2ban/active-bans?sort=network')->json('data'), 'network'));
        $this->assertSame(['203.0.113.0/24'], array_column((array) $this->getJson('/api/v2/fail2ban/active-bans?q=203.0')->json('data'), 'network'));
        $this->assertSame(['203.0.113.0/24'], array_column((array) $this->getJson('/api/v2/fail2ban/active-bans?queuedForLifting=true')->json('data'), 'network'));
        $this->assertCount(3, (array) $this->getJson('/api/v2/fail2ban/active-bans?queuedForLifting=false')->json('data'));
        $this->getJson('/api/v2/fail2ban/active-bans?limit=1&offset=1')->assertOk()->assertJsonPath('meta', ['total' => 4, 'limit' => 1, 'offset' => 1]);
    }

    public function test_expired_bans_report_zero_remaining_seconds(): void
    {
        MailcowLogs::activeBan('192.0.2.200/32', now()->getTimestamp() - 5);
        $this->asAdmin();

        $this->getJson('/api/v2/fail2ban/active-bans?q=192.0.2.200')->assertOk()->assertJsonPath('data.0.remainingSeconds', 0);
    }

    public function test_permanent_bans_newest_first(): void
    {
        $this->asAdmin();

        $data = (array) $this->getJson('/api/v2/fail2ban/permanent-bans')->assertOk()->json('data');

        $this->assertSame(['2001:db8:1::/48', '192.0.2.66/32'], array_column($data, 'network'));
        $this->assertSame('192.0.2.66', $data[1]['address']);
        $this->assertSame(now()->subHour()->utc()->format('Y-m-d\TH:i:s\Z'), $data[1]['startedAt']);
        $this->assertSame(['192.0.2.66/32', '2001:db8:1::/48'], array_column((array) $this->getJson('/api/v2/fail2ban/permanent-bans?sort=network')->json('data'), 'network'));
    }

    public function test_invalid_sort_is_refused(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $this->getJson('/api/v2/fail2ban/active-bans?sort=address')->assertStatus(422)->assertJsonPath('errors.sort.0.code', 'notInList');
    }
}
