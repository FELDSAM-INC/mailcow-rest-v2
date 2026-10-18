<?php

namespace App\Services\Monitoring;

use App\Clients\DockerApi\ContainerSnapshot;
use App\Clients\DockerApi\DockerApiClient;
use App\Clients\DockerApi\DockerApiUnreachable;
use Carbon\CarbonImmutable;

/**
 * The containers of this mailcow installation (feature 011 research R3, FR-008 to FR-010, FR-015):
 * dockerapi's containers of the compose project, without the optional scanners that the
 * configuration skips (`SKIP_CLAMD`, `SKIP_OLEFY`, as legacy dashboard.php). The shared snapshot is
 * at most 5 seconds old; actions read fresh and invalidate it.
 *
 * Protection: the services of `RESTV2_PROTECTED_CONTAINERS` and always this API's own container,
 * which is the service `RESTV2_SELF_SERVICE` or any container whose ID starts with this process's
 * host name (Docker's default host name is the short container ID).
 */
class ContainerRegistry
{
    private const SKIP = '/^([yY][eE][sS]|[yY])+$/';

    public function __construct(
        private readonly DockerApiClient $docker,
        private readonly SharedSnapshotCache $cache,
        private readonly ContainerRoleMap $roles,
    ) {}

    /**
     * The visible containers, collected at most 5 seconds ago (shared by all clients).
     *
     * @throws DockerApiUnreachable
     */
    public function snapshot(): Snapshot
    {
        $snapshot = $this->cache->get('containers', $this->maxAge(), 6.0, fn (): Snapshot => new Snapshot(
            array_map(fn (ContainerSnapshot $c): array => $c->toArray(), $this->docker->containers()),
            CarbonImmutable::now('UTC'),
        ));

        $containers = array_map(fn (array $c): ContainerSnapshot => ContainerSnapshot::fromArray($c), (array) $snapshot->payload);

        return new Snapshot($this->visible($containers), $snapshot->collectedAt);
    }

    /**
     * The visible containers, read from dockerapi now (not cached).
     *
     * @return list<ContainerSnapshot>
     *
     * @throws DockerApiUnreachable
     */
    public function fresh(): array
    {
        return $this->visible($this->docker->containers());
    }

    /**
     * A visible container by service name; null for unknown names, containers of other projects
     * and skipped scanners.
     *
     * @throws DockerApiUnreachable
     */
    public function find(string $service, bool $fresh = false): ?ContainerSnapshot
    {
        foreach ($fresh ? $this->fresh() : $this->snapshot()->payload as $container) {
            if ($container->service === $service) {
                return $container;
            }
        }

        return null;
    }

    public function isProtected(ContainerSnapshot $container): bool
    {
        return $this->isOwnContainer($container) || in_array($container->service, $this->protectedServices(), true);
    }

    public function isOwnContainer(ContainerSnapshot $container): bool
    {
        if ($container->service === (string) config('restv2.monitoring.self_service', 'restv2-mailcow')) {
            return true;
        }

        $hostname = strtolower(trim((string) (config('restv2.monitoring.hostname') ?: '')));

        return strlen($hostname) >= 12 && ctype_xdigit($hostname) && str_starts_with($container->id, $hostname);
    }

    public function role(ContainerSnapshot $container): string
    {
        return $this->roles->role($container->service, $this->isOwnContainer($container));
    }

    /**
     * The actions that would change something now: `start` when not running, `stop` when running
     * and not protected, `restart` unless this is the API's own container.
     *
     * @return list<string>
     */
    public function availableActions(ContainerSnapshot $container): array
    {
        $own = $this->isOwnContainer($container);
        $actions = [];

        if (! $container->running) {
            $actions[] = 'start';
        }

        if ($container->running && ! $this->isProtected($container)) {
            $actions[] = 'stop';
        }

        if (! $own) {
            $actions[] = 'restart';
        }

        return $actions;
    }

    /**
     * Whether the ban service container runs, from the shared snapshot; null when dockerapi is
     * unavailable or the container is not listed (feature 011 research R23).
     */
    public function banServiceRunning(): ?bool
    {
        try {
            return $this->find((string) config('restv2.monitoring.ban_service_container', 'netfilter-mailcow'))?->running;
        } catch (DockerApiUnreachable) {
            return null;
        }
    }

    /** Invalidates the shared container states and the container's resource usage. */
    public function forget(string $service): void
    {
        $this->cache->forget('containers');
        $this->cache->forget('usage:'.$service);
    }

    public function isHidden(string $service): bool
    {
        return ($service === 'clamd-mailcow' && preg_match(self::SKIP, (string) config('restv2.skip_clamd', 'n')) === 1)
            || ($service === 'olefy-mailcow' && preg_match(self::SKIP, (string) config('restv2.skip_olefy', 'n')) === 1);
    }

    public function maxAge(): int
    {
        return (int) config('restv2.monitoring.max_age.containers', 5);
    }

    /**
     * @param  list<ContainerSnapshot>  $containers
     * @return list<ContainerSnapshot>
     */
    private function visible(array $containers): array
    {
        return array_values(array_filter($containers, fn (ContainerSnapshot $c): bool => ! $this->isHidden($c->service)));
    }

    /**
     * @return list<string>
     */
    private function protectedServices(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) config('restv2.monitoring.protected_containers', '')))));
    }
}
