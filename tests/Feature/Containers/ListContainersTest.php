<?php

namespace Tests\Feature\Containers;

use Tests\Support\Monitoring\MonitoringFixtures;
use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\TestCase;

/**
 * Feature 011 T026 (US1 AS-3, AS-6, FR-008, FR-009): the containers of this installation.
 */
class ListContainersTest extends TestCase
{
    use MonitoringTestHelpers;

    /**
     * @param  array<string, string>  $query
     * @return list<array<string, mixed>>
     */
    private function list(array $query = []): array
    {
        return (array) $this->getJson('/api/v2/containers'.($query === [] ? '' : '?'.http_build_query($query)))->assertOk()->json('data');
    }

    public function test_a_stopped_container_is_listed_with_its_last_start(): void
    {
        $this->asAdmin();

        $sogo = collect($this->list(['limit' => '100']))->firstWhere('name', 'sogo-mailcow');

        $this->assertNotNull($sogo);
        $this->assertFalse($sogo['running']);
        $this->assertSame('exited', $sogo['state']);
        $this->assertSame('2026-10-17T22:15:30Z', $sogo['startedAt']);
        $this->assertSame('webmail', $sogo['role']);
        $this->assertSame(['start', 'restart'], $sogo['availableActions']);
        $this->assertFalse($sogo['protected']);
    }

    public function test_every_item_carries_protection_role_actions_and_collection_time(): void
    {
        $this->asAdmin();

        $items = collect($this->list(['limit' => '100']))->keyBy('name');

        $this->assertCount(19, $items);
        $this->assertTrue($items['mysql-mailcow']['protected']);
        $this->assertSame('database', $items['mysql-mailcow']['role']);
        $this->assertSame(['restart'], $items['mysql-mailcow']['availableActions']);
        $this->assertSame([], $items['restv2-mailcow']['availableActions']);
        $this->assertSame('api', $items['restv2-mailcow']['role']);
        $this->assertSame('healthy', $items['nginx-mailcow']['health']);
        $this->assertNull($items['memcached-mailcow']['health']);
        $this->assertSame('ghcr.io/mailcow/postfix:3.10.12-1', $items['postfix-mailcow']['image']);
        $this->assertNotNull($items['postfix-mailcow']['collectedAt']);
    }

    public function test_a_skipped_scanner_and_foreign_containers_are_absent(): void
    {
        config(['restv2.skip_clamd' => 'y']);
        $this->asAdmin();

        $names = array_column($this->list(['limit' => '100']), 'name');

        $this->assertNotContains('clamd-mailcow', $names);
        $this->assertNotContains('website', $names);
        $this->assertCount(18, $names);
    }

    public function test_sorting_by_name_state_and_start_time_with_ties_by_name(): void
    {
        $this->asAdmin();

        $byName = array_column($this->list(['limit' => '100']), 'name');
        $sorted = $byName;
        sort($sorted);
        $this->assertSame($sorted, $byName, 'the default order is by name, ascending');

        $byNameDesc = array_column($this->list(['limit' => '100', 'order' => 'desc']), 'name');
        $this->assertSame(array_reverse($sorted), $byNameDesc);

        $byState = $this->list(['limit' => '100', 'sort' => 'state']);
        $this->assertSame('sogo-mailcow', $byState[0]['name'], 'exited sorts before running');
        $running = array_column(array_slice($byState, 1), 'name');
        $runningSorted = $running;
        sort($runningSorted);
        $this->assertSame($runningSorted, $running, 'ties are broken by name');

        $byStart = $this->list(['limit' => '100', 'sort' => 'startedAt']);
        $this->assertSame('sogo-mailcow', $byStart[0]['name'], 'the earliest start comes first');
    }

    public function test_the_running_filter(): void
    {
        $this->asAdmin();

        $stopped = $this->list(['running' => 'false']);
        $this->assertSame(['sogo-mailcow'], array_column($stopped, 'name'));
        $this->assertCount(18, $this->list(['running' => 'true', 'limit' => '100']));
    }

    public function test_the_search_covers_name_and_image(): void
    {
        $this->asAdmin();

        $this->assertSame(['postfix-mailcow', 'postfix-tlspol-mailcow'], array_column($this->list(['q' => 'POSTFIX']), 'name'));
        $this->assertCount(15, $this->list(['q' => 'ghcr.io', 'limit' => '100']));
    }

    public function test_paging_and_meta(): void
    {
        $this->asAdmin();

        $response = $this->getJson('/api/v2/containers?limit=5&offset=5')->assertOk();

        $response->assertJsonPath('meta', ['total' => 19, 'limit' => 5, 'offset' => 5]);
        $this->assertCount(5, $response->json('data'));
    }

    public function test_invalid_list_parameters_are_refused(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $this->getJson('/api/v2/containers?limit=0')->assertStatus(422)->assertJsonPath('errors.limit.0.code', 'outOfRange');
        $this->getJson('/api/v2/containers?sort=image')->assertStatus(422)->assertJsonPath('errors.sort.0.code', 'notInList');
        $this->getJson('/api/v2/containers?running=maybe')->assertStatus(422)->assertJsonPath('errors.running.0.code', 'invalidType');
    }

    public function test_no_response_contains_an_environment_value(): void
    {
        $this->asAdmin();

        $body = (string) $this->getJson('/api/v2/containers?limit=100')->assertOk()->getContent();

        foreach (MonitoringFixtures::PLANTED_SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_an_unavailable_container_service_is_a_temporary_failure(): void
    {
        $this->dockerApi()->failNext('containers', 'timeout');
        $this->asAdmin();

        $this->getJson('/api/v2/containers')->assertStatus(503)->assertJsonPath('type', '/api/v2/problems/temporary-failure');
    }
}
