<?php

namespace Tests\Feature\Containers;

use Illuminate\Testing\TestResponse;
use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\TestCase;

/**
 * Feature 011 T037 (US2 AS-2, FR-014, FR-016, research R4): starting a container, with legacy's
 * four attempts 1.5 seconds apart.
 */
class StartContainerTest extends TestCase
{
    use MonitoringTestHelpers;

    private function start(string $service): TestResponse
    {
        return $this->postEmpty("/api/v2/containers/{$service}/start", $this->csrf());
    }

    public function test_a_running_container_is_already_in_state(): void
    {
        $this->asAdmin();

        $this->start('postfix-mailcow')
            ->assertOk()
            ->assertJsonPath('data.action', 'start')
            ->assertJsonPath('data.outcome', 'alreadyInState')
            ->assertJsonPath('data.container.running', true);
        $this->dockerApi()->assertNoAction();
    }

    public function test_a_stopped_container_is_started(): void
    {
        $this->asAdmin();

        $this->start('sogo-mailcow')
            ->assertOk()
            ->assertJsonPath('data.outcome', 'done')
            ->assertJsonPath('data.container.name', 'sogo-mailcow')
            ->assertJsonPath('data.container.running', true)
            ->assertJsonPath('data.container.state', 'running');
        $this->dockerApi()->assertCallCount('start', 1, 'sogo-mailcow');
        $this->assertSame([], $this->sleeper()->slept);
    }

    public function test_a_container_that_starts_on_the_third_attempt(): void
    {
        $this->dockerApi()->resistActions('sogo-mailcow', 2);
        $this->asAdmin();

        $this->start('sogo-mailcow')->assertOk()->assertJsonPath('data.outcome', 'done');

        $this->dockerApi()->assertCallCount('start', 3, 'sogo-mailcow');
        $this->assertSame([1.5, 1.5], $this->sleeper()->slept);
    }

    public function test_a_container_that_never_starts_fails_after_four_attempts(): void
    {
        $this->dockerApi()->resistActions('sogo-mailcow', 10);
        $this->asAdmin();

        $this->start('sogo-mailcow')
            ->assertStatus(502)
            ->assertJsonPath('type', '/api/v2/problems/container-action-failed')
            ->assertJsonPath('container', 'sogo-mailcow')
            ->assertJsonPath('serviceMessage', 'The container did not start.');
        $this->dockerApi()->assertCallCount('start', 4, 'sogo-mailcow');
        $this->assertSame([1.5, 1.5, 1.5], $this->sleeper()->slept);
    }

    public function test_the_last_service_message_is_reported(): void
    {
        $this->dockerApi()->resistActions('sogo-mailcow', 10);
        $this->dockerApi()->failAlways('start', 'danger:Cannot start service sogo-mailcow: port is already allocated');
        $this->asAdmin();

        $this->start('sogo-mailcow')
            ->assertStatus(502)
            ->assertJsonPath('serviceMessage', 'Cannot start service sogo-mailcow: port is already allocated');
    }

    public function test_a_protected_container_may_be_started(): void
    {
        $this->dockerApi()->setRunning('mysql-mailcow', false);
        $this->asAdmin();

        $this->start('mysql-mailcow')->assertOk()->assertJsonPath('data.outcome', 'done');
    }

    public function test_a_timeout_has_an_unknown_outcome(): void
    {
        $this->dockerApi()->failNext('start', 'timeout');
        $this->asAdmin();

        $this->start('sogo-mailcow')
            ->assertStatus(504)
            ->assertJsonPath('type', '/api/v2/problems/outcome-unknown')
            ->assertJsonPath('step', 'start');
    }

    public function test_an_unavailable_container_service_changes_nothing(): void
    {
        $this->dockerApi()->failNext('containers', 'unreachable');
        $this->asAdmin();

        $this->start('sogo-mailcow')->assertStatus(503)->assertJsonPath('type', '/api/v2/problems/temporary-failure');
        $this->dockerApi()->assertNoAction();
    }

    public function test_an_unreachable_service_on_the_first_attempt_is_a_temporary_failure(): void
    {
        $this->dockerApi()->failNext('start', 'unreachable');
        $this->asAdmin();

        $this->start('sogo-mailcow')->assertStatus(503)->assertJsonPath('type', '/api/v2/problems/temporary-failure');
    }

    public function test_an_unreachable_service_after_a_sent_attempt_has_an_unknown_outcome(): void
    {
        $this->dockerApi()->resistActions('sogo-mailcow', 1);
        $this->dockerApi()->failCall('start', 2, 'unreachable');
        $this->asAdmin();

        $this->start('sogo-mailcow')
            ->assertStatus(504)
            ->assertJsonPath('type', '/api/v2/problems/outcome-unknown')
            ->assertJsonPath('step', 'start');
    }

    public function test_unknown_foreign_and_hidden_containers_are_not_found(): void
    {
        config(['restv2.skip_clamd' => 'y']);
        $this->asAdmin();

        foreach (['unknown-mailcow', 'website', 'clamd-mailcow'] as $service) {
            $this->start($service)->assertNotFound();
        }

        $this->dockerApi()->assertNoAction();
    }

    public function test_a_read_write_administrator_key_may_start(): void
    {
        $this->postEmpty('/api/v2/containers/sogo-mailcow/start', $this->adminKey())->assertOk();
    }
}
