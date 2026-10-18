<?php

namespace Tests\Feature\Containers;

use Tests\Support\Monitoring\MonitoringFixtures;
use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\TestCase;

/**
 * Feature 011 T028 (US1 AS-4, AS-5, FR-011, research R7): the resource usage of a running
 * container.
 */
class ContainerResourceUsageTest extends TestCase
{
    use MonitoringTestHelpers;

    public function test_the_usage_of_a_running_container(): void
    {
        $this->dockerApi()->withStats('postfix-mailcow', MonitoringFixtures::stats('stats-postfix-3.json'));
        $this->asAdmin();

        $data = $this->getJson('/api/v2/containers/postfix-mailcow/resource-usage')->assertOk()->json('data');

        $this->assertSame('postfix-mailcow', $data['containerName']);
        $this->assertSame('2026-10-18T10:00:10Z', $data['sampledAt']);
        $this->assertSame('2026-10-18T10:00:05Z', $data['previousSampledAt']);
        $this->assertEquals(12, $data['cpuPercent']);
        $this->assertEquals(200000, $data['disk']['readBytesPerSecond']);
        $this->assertEquals(100000, $data['network']['receiveBytesPerSecond']);
        $this->dockerApi()->assertCallCount('containerStats', 1, 'postfix-mailcow');
    }

    public function test_the_first_request_has_one_sample_and_no_rates(): void
    {
        $this->dockerApi()->withStats('postfix-mailcow', MonitoringFixtures::stats('stats-postfix-1.json'));
        $this->asAdmin();

        $this->getJson('/api/v2/containers/postfix-mailcow/resource-usage')
            ->assertOk()
            ->assertJsonPath('data.previousSampledAt', null)
            ->assertJsonPath('data.disk.readBytesPerSecond', null);
    }

    public function test_a_container_that_is_not_running_is_refused_at_once(): void
    {
        $this->asAdmin();

        $this->getJson('/api/v2/containers/sogo-mailcow/resource-usage')
            ->assertStatus(409)
            ->assertJsonPath('type', '/api/v2/problems/container-not-running')
            ->assertJsonPath('container', 'sogo-mailcow');
        $this->dockerApi()->assertNotCalled('containerStats');
    }

    public function test_a_timeout_while_the_container_runs_is_a_temporary_failure(): void
    {
        $this->dockerApi()->failNext('containerStats', 'timeout');
        $this->asAdmin();

        $this->getJson('/api/v2/containers/postfix-mailcow/resource-usage')
            ->assertStatus(503)
            ->assertJsonPath('type', '/api/v2/problems/temporary-failure');
    }

    public function test_a_timeout_after_the_container_stopped_is_container_not_running(): void
    {
        $this->asAdmin();
        $this->getJson('/api/v2/containers')->assertOk();
        $this->dockerApi()->setRunning('postfix-mailcow', false);
        $this->dockerApi()->failNext('containerStats', 'timeout');

        $this->getJson('/api/v2/containers/postfix-mailcow/resource-usage')
            ->assertStatus(409)
            ->assertJsonPath('type', '/api/v2/problems/container-not-running');
    }

    public function test_no_samples_after_the_container_stopped_is_container_not_running(): void
    {
        $this->asAdmin();
        $this->getJson('/api/v2/containers')->assertOk();
        $this->dockerApi()->setRunning('postfix-mailcow', false);

        $this->getJson('/api/v2/containers/postfix-mailcow/resource-usage')->assertStatus(409);
    }

    public function test_the_usage_is_cached_for_five_seconds_per_service(): void
    {
        $this->dockerApi()->withStats('postfix-mailcow', MonitoringFixtures::stats('stats-postfix-3.json'));
        $this->dockerApi()->withStats('dovecot-mailcow', MonitoringFixtures::stats('stats-postfix-1.json'));
        $this->asAdmin();

        $this->getJson('/api/v2/containers/postfix-mailcow/resource-usage')->assertOk();
        $this->getJson('/api/v2/containers/dovecot-mailcow/resource-usage')->assertOk();
        $this->travel(4)->seconds();
        $this->getJson('/api/v2/containers/postfix-mailcow/resource-usage')->assertOk();
        $this->dockerApi()->assertCallCount('containerStats', 1, 'postfix-mailcow');

        $this->travel(2)->seconds();
        $this->getJson('/api/v2/containers/postfix-mailcow/resource-usage')->assertOk();
        $this->dockerApi()->assertCallCount('containerStats', 2, 'postfix-mailcow');
    }

    public function test_an_unknown_container_is_not_found(): void
    {
        $this->asAdmin();

        $this->getJson('/api/v2/containers/unknown-mailcow/resource-usage')->assertNotFound();
        $this->getJson('/api/v2/containers/website/resource-usage')->assertNotFound();
    }
}
