<?php

namespace Tests\Feature\Monitoring;

use Carbon\CarbonImmutable;
use Tests\Support\Monitoring\MonitoringFixtures;
use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\TestCase;

/**
 * Feature 011 T023 (US1 AS-1, FR-005, FR-006, research R6): the system overview of the host.
 */
class HostOverviewTest extends TestCase
{
    use MonitoringTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        // dockerapi's system_time has whole seconds: a frozen clock keeps the ages exact.
        $this->freezeSecond();
    }

    public function test_the_overview_maps_the_host_figures(): void
    {
        $this->asAdmin();

        $response = $this->getJson('/api/v2/host')->assertOk();

        $data = $response->json('data');
        $this->assertSame('mail.example.org', $data['hostName']);
        $this->assertSame('Europe/Prague', $data['timeZone']);
        $this->assertSame('x86_64', $data['architecture']);
        $this->assertSame(123456, $data['uptimeSeconds']);
        $this->assertSame(['cores' => 8, 'usagePercent' => 12.5], $data['cpu']);
        $this->assertSame(['totalBytes' => 16777216000, 'usagePercent' => 41.2], $data['memory']);
        $this->assertSame(['totalBytes' => 2147479552, 'usedBytes' => 104857600, 'usagePercent' => 4.9], $data['swap']);
        $this->assertSame(['tag' => null, 'branch' => null, 'commit' => null, 'commitDate' => null, 'projectUrl' => null], $data['version']);
        $this->assertStringEndsWith('Z', $data['currentTime']);
        $this->assertLessThanOrEqual(2, abs(CarbonImmutable::parse($data['collectedAt'])->diffInSeconds(CarbonImmutable::now('UTC'))));
    }

    public function test_the_collection_time_is_parsed_in_the_configured_time_zone(): void
    {
        $stats = MonitoringFixtures::hostStats();
        $stats['system_time'] = CarbonImmutable::now('Europe/Prague')->subSeconds(3)->format('d.m.Y H:i:s');
        $this->dockerApi()->withHostStats($stats);
        $this->asAdmin();

        $collectedAt = CarbonImmutable::parse((string) $this->getJson('/api/v2/host')->assertOk()->json('data.collectedAt'));

        $this->assertEqualsWithDelta(3, CarbonImmutable::now('UTC')->diffInSeconds($collectedAt, true), 1.5);
    }

    public function test_a_second_request_within_ten_seconds_makes_no_call(): void
    {
        $this->asAdmin();

        $this->getJson('/api/v2/host')->assertOk();
        $this->travel(9)->seconds();
        $this->getJson('/api/v2/host')->assertOk();
        $this->dockerApi()->assertCallCount('hostStats', 1);

        $this->travel(2)->seconds();
        $this->getJson('/api/v2/host')->assertOk();
        $this->dockerApi()->assertCallCount('hostStats', 2);
    }

    public function test_figures_dockerapi_had_already_cached_are_served_only_for_the_rest_of_their_age(): void
    {
        $stats = MonitoringFixtures::hostStats();
        $stats['system_time'] = CarbonImmutable::now('Europe/Prague')->subSeconds(8)->format('d.m.Y H:i:s');
        $this->dockerApi()->withHostStats($stats);
        $this->asAdmin();

        $this->getJson('/api/v2/host')->assertOk();
        $this->travel(1)->seconds();
        $this->getJson('/api/v2/host')->assertOk();
        $this->dockerApi()->assertCallCount('hostStats', 1);

        $this->travel(2)->seconds();
        $this->getJson('/api/v2/host')->assertOk();
        $this->dockerApi()->assertCallCount('hostStats', 2);
    }

    public function test_an_unavailable_container_service_is_a_temporary_failure(): void
    {
        $this->dockerApi()->failNext('hostStats', 'unreachable');
        $this->asAdmin();

        $this->getJson('/api/v2/host')
            ->assertStatus(503)
            ->assertJsonPath('type', '/api/v2/problems/temporary-failure')
            ->assertHeader('Retry-After');
    }

    public function test_a_read_only_administrator_key_may_read_it(): void
    {
        $this->getJson('/api/v2/host', $this->adminKey('readOnly'))->assertOk();
    }
}
