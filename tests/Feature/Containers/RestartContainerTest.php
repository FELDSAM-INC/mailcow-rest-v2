<?php

namespace Tests\Feature\Containers;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\TestCase;

/**
 * Feature 011 T039 (US2 AS-1, AS-3, AS-5, FR-014, FR-016, SC-009): restarting a container.
 */
class RestartContainerTest extends TestCase
{
    use MonitoringTestHelpers;

    /**
     * @return TestResponse<Response>
     */
    private function restart(string $service): TestResponse
    {
        return $this->postEmpty("/api/v2/containers/{$service}/restart", $this->csrf());
    }

    public function test_a_running_container_is_restarted_with_a_new_start_time(): void
    {
        $this->travel(1)->hours();
        $this->asAdmin();

        $response = $this->restart('postfix-mailcow')
            ->assertOk()
            ->assertJsonPath('data.action', 'restart')
            ->assertJsonPath('data.outcome', 'done')
            ->assertJsonPath('data.container.running', true);

        $this->assertNotSame('2026-10-18T06:00:00Z', $response->json('data.container.startedAt'));
        $this->dockerApi()->assertRestarted('postfix-mailcow');
    }

    public function test_a_stopped_container_is_started_by_restart(): void
    {
        $this->asAdmin();

        $this->restart('sogo-mailcow')->assertOk()->assertJsonPath('data.outcome', 'done')->assertJsonPath('data.container.running', true);
    }

    public function test_a_protected_container_may_be_restarted(): void
    {
        $this->asAdmin();

        $this->restart('mysql-mailcow')->assertOk()->assertJsonPath('data.outcome', 'done');
    }

    public function test_the_own_container_cannot_be_restarted(): void
    {
        $this->asAdmin();

        $this->restart('restv2-mailcow')->assertStatus(409)->assertJsonPath('type', '/api/v2/problems/container-protected');
        $this->dockerApi()->assertNoAction();
    }

    public function test_a_timeout_has_an_unknown_outcome(): void
    {
        $this->dockerApi()->failNext('restart', 'timeout');
        $this->asAdmin();

        $this->restart('sogo-mailcow')->assertStatus(504)->assertJsonPath('type', '/api/v2/problems/outcome-unknown')->assertJsonPath('step', 'restart');
    }

    public function test_a_danger_answer_is_a_container_action_failure(): void
    {
        $this->dockerApi()->failNext('restart', 'danger:409 Client Error: Conflict');
        $this->asAdmin();

        $this->restart('sogo-mailcow')
            ->assertStatus(502)
            ->assertJsonPath('type', '/api/v2/problems/container-action-failed')
            ->assertJsonPath('serviceMessage', '409 Client Error: Conflict');
    }

    public function test_an_action_invalidates_the_shared_container_states(): void
    {
        $this->asAdmin();
        $this->getJson('/api/v2/containers')->assertOk();
        $before = $this->dockerApi()->callCount('containers');

        $this->restart('sogo-mailcow')->assertOk();
        $afterAction = $this->dockerApi()->callCount('containers');
        $this->getJson('/api/v2/containers')->assertOk();

        $this->assertSame($afterAction + 1, $this->dockerApi()->callCount('containers'), 'the next list did not read fresh');
        $this->assertGreaterThan($before, $afterAction);
    }
}
