<?php

namespace Tests\Feature\Monitoring;

use Illuminate\Support\Facades\Redis;
use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\TestCase;

/**
 * Feature 011 T100 (US7 AS-4, FR-021, research R11): the public addresses, only with the IP check
 * enabled.
 */
class PublicAddressesTest extends TestCase
{
    use MonitoringTestHelpers;

    public function test_a_disabled_ip_check_makes_no_request(): void
    {
        $this->asAdmin();

        $this->getJson('/api/v2/host/public-addresses')->assertStatus(409)->assertJsonPath('type', '/api/v2/problems/ip-check-disabled');
        Redis::connection('mailcow')->command('set', ['IP_CHECK', '0']);
        $this->getJson('/api/v2/host/public-addresses')->assertStatus(409);

        $this->assertSame(0, $this->publicAddresses()->callCount());
    }

    public function test_both_addresses(): void
    {
        Redis::connection('mailcow')->command('set', ['IP_CHECK', '1']);
        $this->asAdmin();

        $this->getJson('/api/v2/host/public-addresses')
            ->assertOk()
            ->assertJsonPath('data.ipv4', '198.51.100.7')
            ->assertJsonPath('data.ipv6', '2001:db8::7');
        $this->assertSame(1, $this->publicAddresses()->callCount());
    }

    public function test_an_unavailable_family_is_null(): void
    {
        Redis::connection('mailcow')->command('set', ['IP_CHECK', '1']);
        $this->publicAddresses()->withAnswers('198.51.100.7', null);
        $this->asAdmin();

        $this->getJson('/api/v2/host/public-addresses')->assertOk()->assertJsonPath('data.ipv6', null)->assertJsonPath('data.ipv4', '198.51.100.7');
    }
}
