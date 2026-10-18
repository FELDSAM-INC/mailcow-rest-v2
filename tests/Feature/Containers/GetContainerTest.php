<?php

namespace Tests\Feature\Containers;

use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\TestCase;

/**
 * Feature 011 T027 (FR-010, FR-003): one container by its service name.
 */
class GetContainerTest extends TestCase
{
    use MonitoringTestHelpers;

    public function test_a_container_is_inspected_by_its_service_name(): void
    {
        $this->asAdmin();

        $this->getJson('/api/v2/containers/postfix-mailcow')
            ->assertOk()
            ->assertJsonPath('data.name', 'postfix-mailcow')
            ->assertJsonPath('data.running', true)
            ->assertJsonPath('data.state', 'running')
            ->assertJsonPath('data.role', 'mailTransfer')
            ->assertJsonPath('data.availableActions', ['stop', 'restart']);
    }

    public function test_foreign_hidden_and_unknown_containers_are_not_found(): void
    {
        config(['restv2.skip_clamd' => 'y']);
        $this->asAdmin();

        foreach (['website', 'clamd-mailcow', 'unknown-mailcow'] as $name) {
            $this->getJson("/api/v2/containers/{$name}")->assertNotFound()->assertJsonPath('type', 'about:blank');
        }
    }

    public function test_an_invalid_name_matches_no_route(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $this->getJson('/api/v2/containers/Invalid_Name')->assertNotFound();
        $this->getJson('/api/v2/containers/-dash')->assertNotFound();
    }
}
