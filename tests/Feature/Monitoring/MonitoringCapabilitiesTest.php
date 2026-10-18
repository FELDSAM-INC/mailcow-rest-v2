<?php

namespace Tests\Feature\Monitoring;

use Illuminate\Support\Facades\Redis;
use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\TestCase;

/**
 * Feature 011 T029 (FR-069, research R32): the settings and limits a client needs before it polls,
 * pages or acts.
 */
class MonitoringCapabilitiesTest extends TestCase
{
    use MonitoringTestHelpers;

    public function test_the_capabilities(): void
    {
        $this->asAdmin();

        $data = $this->getJson('/api/v2/capabilities/monitoring')->assertOk()->json('data');

        $this->assertFalse($data['ipCheckEnabled']);
        $this->assertSame(5, $data['pollIntervalSeconds']);
        $this->assertSame(['containerStates' => 5, 'hostStatistics' => 10, 'containerResourceUsage' => 15, 'mailStorage' => 60], $data['maxAgeSeconds']);
        $this->assertSame(['policy' => 'entries', 'maxEntries' => 10000], $data['logRetention']['postfix']);
        $this->assertSame(['policy' => 'entries', 'maxEntries' => 10000], $data['logRetention']['legacyApiRequests']);
        $this->assertSame(['policy' => 'entries', 'maxEntries' => 101], $data['logRetention']['autodiscover']);
        $this->assertSame(['policy' => 'unbounded', 'maxEntries' => null], $data['logRetention']['legacyUiActivity']);
        $this->assertSame(['policy' => 'cleanedDaily', 'maxEntries' => null], $data['logRetention']['logins']);
        $this->assertSame('127.0.0.1', $data['clientAddress']);
        $this->assertSame([
            'defaultPageSize' => 25,
            'maxPageSize' => 1000,
            'mailQueueBulkMaxIds' => 1000,
            'mailQueueListingMax' => 10000,
            'fail2banRulesMax' => 100,
            'fail2banRuleMaxLength' => 1000,
            'fail2banListMaxEntries' => 10000,
        ], $data['limits']);
        $this->dockerApi()->assertNothingCalled();
    }

    public function test_the_ip_check_follows_the_customisation_setting(): void
    {
        $this->asAdmin();

        Redis::connection('mailcow')->command('set', ['IP_CHECK', '1']);
        $this->getJson('/api/v2/capabilities/monitoring')->assertOk()->assertJsonPath('data.ipCheckEnabled', true);

        Redis::connection('mailcow')->command('set', ['IP_CHECK', '0']);
        $this->getJson('/api/v2/capabilities/monitoring')->assertOk()->assertJsonPath('data.ipCheckEnabled', false);
    }

    public function test_the_log_length_follows_log_lines(): void
    {
        config(['restv2.log_lines' => 499]);
        $this->asAdmin();

        $this->getJson('/api/v2/capabilities/monitoring')->assertOk()->assertJsonPath('data.logRetention.dovecot.maxEntries', 500);
    }

    public function test_the_client_address_is_the_callers(): void
    {
        $this->asAdmin();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->getJson('/api/v2/capabilities/monitoring')
            ->assertOk()
            ->assertJsonPath('data.clientAddress', '203.0.113.9');
    }

    public function test_a_read_only_administrator_key_may_read_it(): void
    {
        $this->getJson('/api/v2/capabilities/monitoring', $this->adminKey('readOnly'))->assertOk();
    }
}
