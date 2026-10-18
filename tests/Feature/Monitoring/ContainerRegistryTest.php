<?php

namespace Tests\Feature\Monitoring;

use App\Clients\DockerApi\ContainerSnapshot;
use App\Services\Monitoring\ContainerRegistry;
use Tests\Support\Monitoring\MonitoringFixtures;
use Tests\TestCase;

/**
 * Feature 011 T011 (research R3, FR-008, FR-015): the containers of this installation, hidden
 * optional scanners, protection and the own container, available actions and the ban service
 * indicator.
 */
class ContainerRegistryTest extends TestCase
{
    private function registry(): ContainerRegistry
    {
        return $this->app->make(ContainerRegistry::class);
    }

    /**
     * @return list<string>
     */
    private function services(): array
    {
        return array_map(fn (ContainerSnapshot $c): string => $c->service, $this->registry()->snapshot()->payload);
    }

    public function test_the_snapshot_lists_the_project_containers_only(): void
    {
        $services = $this->services();

        $this->assertCount(19, $services);
        $this->assertNotContains('website', $services);
    }

    public function test_skipped_scanners_are_hidden(): void
    {
        config(['restv2.skip_clamd' => 'y', 'restv2.skip_olefy' => 'yes']);

        $services = $this->services();

        $this->assertNotContains('clamd-mailcow', $services);
        $this->assertNotContains('olefy-mailcow', $services);
        $this->assertNull($this->registry()->find('clamd-mailcow'));
        $this->assertNull($this->registry()->find('olefy-mailcow', fresh: true));
    }

    public function test_scanners_are_listed_unless_skipped(): void
    {
        config(['restv2.skip_clamd' => 'n', 'restv2.skip_olefy' => 'no']);

        $services = $this->services();

        $this->assertContains('clamd-mailcow', $services);
        $this->assertContains('olefy-mailcow', $services);
    }

    public function test_find_answers_null_for_foreign_hidden_and_unknown_names(): void
    {
        $this->assertNotNull($this->registry()->find('postfix-mailcow'));
        $this->assertNull($this->registry()->find('website'));
        $this->assertNull($this->registry()->find('unknown-mailcow'));
    }

    public function test_protection_follows_the_setting_and_always_covers_the_own_container(): void
    {
        $registry = $this->registry();

        foreach (['dockerapi-mailcow', 'mysql-mailcow', 'redis-mailcow', 'nginx-mailcow', 'restv2-mailcow'] as $service) {
            $this->assertTrue($registry->isProtected($registry->find($service)), "{$service} is not protected");
        }

        $this->assertFalse($registry->isProtected($registry->find('postfix-mailcow')));

        config(['restv2.monitoring.protected_containers' => 'postfix-mailcow']);
        $this->assertTrue($registry->isProtected($registry->find('postfix-mailcow')));
        $this->assertFalse($registry->isProtected($registry->find('mysql-mailcow')));
        $this->assertTrue($registry->isProtected($registry->find('restv2-mailcow')), 'the own container is always protected');
    }

    public function test_the_own_container_is_recognised_by_service_and_by_host_name(): void
    {
        $registry = $this->registry();
        $this->assertTrue($registry->isOwnContainer($registry->find('restv2-mailcow')));
        $this->assertFalse($registry->isOwnContainer($registry->find('acme-mailcow')));

        config(['restv2.monitoring.hostname' => substr(MonitoringFixtures::containerId('acme-mailcow'), 0, 12)]);

        $acme = $registry->find('acme-mailcow');
        $this->assertTrue($registry->isOwnContainer($acme));
        $this->assertTrue($registry->isProtected($acme));
        $this->assertSame('api', $registry->role($acme));
    }

    public function test_roles(): void
    {
        $registry = $this->registry();

        $this->assertSame('mailTransfer', $registry->role($registry->find('postfix-mailcow')));
        $this->assertSame('api', $registry->role($registry->find('restv2-mailcow')));
    }

    public function test_available_actions_per_state(): void
    {
        $registry = $this->registry();

        $this->assertSame(['stop', 'restart'], $registry->availableActions($registry->find('postfix-mailcow')));
        $this->assertSame(['restart'], $registry->availableActions($registry->find('mysql-mailcow')));
        $this->assertSame(['start', 'restart'], $registry->availableActions($registry->find('sogo-mailcow')));
        $this->assertSame([], $registry->availableActions($registry->find('restv2-mailcow')));
    }

    public function test_the_snapshot_is_cached_for_five_seconds(): void
    {
        $registry = $this->registry();
        $registry->snapshot();
        $this->travel(4)->seconds();
        $registry->snapshot();
        $this->dockerApi()->assertCallCount('containers', 1);

        $this->travel(2)->seconds();
        $registry->snapshot();
        $this->dockerApi()->assertCallCount('containers', 2);
    }

    public function test_fresh_reads_bypass_the_cache_and_forget_invalidates_it(): void
    {
        $registry = $this->registry();
        $registry->snapshot();
        $registry->fresh();
        $registry->find('postfix-mailcow', fresh: true);
        $this->dockerApi()->assertCallCount('containers', 3);

        $registry->forget('postfix-mailcow');
        $registry->snapshot();
        $this->dockerApi()->assertCallCount('containers', 4);
    }

    public function test_the_ban_service_indicator(): void
    {
        $this->assertTrue($this->registry()->banServiceRunning());

        $this->dockerApi()->setRunning('netfilter-mailcow', false);
        $this->registry()->forget('netfilter-mailcow');
        $this->assertFalse($this->registry()->banServiceRunning());

        $this->registry()->forget('netfilter-mailcow');
        $this->dockerApi()->failNext('containers', 'unreachable');
        $this->assertNull($this->registry()->banServiceRunning());
    }

    public function test_the_ban_service_indicator_is_null_when_the_container_is_not_listed(): void
    {
        config(['restv2.monitoring.ban_service_container' => 'netfilter-custom']);

        $this->assertNull($this->registry()->banServiceRunning());
    }
}
