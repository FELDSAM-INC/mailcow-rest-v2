<?php

namespace Tests\Feature\Containers;

use Illuminate\Testing\TestResponse;
use Tests\Support\Monitoring\MonitoringFixtures;
use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\TestCase;

/**
 * Feature 011 T038 (US2 AS-3, AS-4, FR-014, FR-015): stopping a container; protected containers
 * and this API's own container cannot be stopped.
 */
class StopContainerTest extends TestCase
{
    use MonitoringTestHelpers;

    private function stop(string $service): TestResponse
    {
        return $this->postEmpty("/api/v2/containers/{$service}/stop", $this->csrf());
    }

    public function test_a_protected_container_cannot_be_stopped(): void
    {
        $this->asAdmin();

        $this->stop('mysql-mailcow')
            ->assertStatus(409)
            ->assertJsonPath('type', '/api/v2/problems/container-protected')
            ->assertJsonPath('container', 'mysql-mailcow');
        $this->dockerApi()->assertNoAction();
    }

    public function test_the_own_container_cannot_be_stopped(): void
    {
        $this->asAdmin();
        $this->stop('restv2-mailcow')->assertStatus(409)->assertJsonPath('type', '/api/v2/problems/container-protected');

        config(['restv2.monitoring.hostname' => substr(MonitoringFixtures::containerId('acme-mailcow'), 0, 12)]);
        $this->stop('acme-mailcow')->assertStatus(409)->assertJsonPath('container', 'acme-mailcow');
        $this->dockerApi()->assertNoAction();
    }

    public function test_a_stopped_container_is_already_in_state(): void
    {
        $this->asAdmin();

        $this->stop('sogo-mailcow')->assertOk()->assertJsonPath('data.outcome', 'alreadyInState')->assertJsonPath('data.container.running', false);
        $this->dockerApi()->assertNoAction();
    }

    public function test_a_running_container_is_stopped(): void
    {
        $this->asAdmin();

        $this->stop('clamd-mailcow')
            ->assertOk()
            ->assertJsonPath('data.action', 'stop')
            ->assertJsonPath('data.outcome', 'done')
            ->assertJsonPath('data.container.running', false)
            ->assertJsonPath('data.container.availableActions', ['start', 'restart']);
        $this->dockerApi()->assertCallCount('stop', 1, 'clamd-mailcow');
    }

    public function test_stop_retries_like_start(): void
    {
        $this->dockerApi()->resistActions('clamd-mailcow', 1);
        $this->asAdmin();

        $this->stop('clamd-mailcow')->assertOk()->assertJsonPath('data.outcome', 'done');
        $this->dockerApi()->assertCallCount('stop', 2, 'clamd-mailcow');
        $this->assertSame([1.5], $this->sleeper()->slept);
    }

    public function test_the_protected_list_is_a_setting(): void
    {
        config(['restv2.monitoring.protected_containers' => 'clamd-mailcow']);
        $this->asAdmin();

        $this->stop('clamd-mailcow')->assertStatus(409);
        $this->stop('mysql-mailcow')->assertOk()->assertJsonPath('data.outcome', 'done');
    }
}
