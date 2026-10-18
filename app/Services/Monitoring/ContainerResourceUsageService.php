<?php

namespace App\Services\Monitoring;

use App\Clients\DockerApi\DockerApiClient;
use App\Clients\DockerApi\DockerApiTimeout;
use App\Clients\DockerApi\DockerApiUnreachable;
use App\Exceptions\ProblemException;
use App\Support\Http\ProblemType;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The resource usage of a running container (feature 011 research R7, FR-011): a container that is
 * not running is refused at once (`container-not-running`; dockerapi's stats endpoint would wait
 * forever); otherwise dockerapi's samples (5 second timeout) are computed into counters and rates
 * and shared by all clients for 5 seconds per container. A timeout or a missing sample re-reads the
 * state: a container that stopped meanwhile is `container-not-running`, else a temporary failure.
 */
class ContainerResourceUsageService
{
    public function __construct(
        private readonly DockerApiClient $docker,
        private readonly SharedSnapshotCache $cache,
        private readonly ContainerRegistry $containers,
        private readonly DockerStatsCalculator $calculator,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws DockerApiUnreachable
     */
    public function usage(string $service): array
    {
        $container = $this->containers->find($service) ?? throw new NotFoundHttpException('The container does not exist.');

        if (! $container->running) {
            throw self::notRunning($service);
        }

        $snapshot = $this->cache->get('usage:'.$service, (int) config('restv2.monitoring.max_age.usage_cache', 5), 6.0, function () use ($service): Snapshot {
            try {
                $samples = $this->docker->containerStats($service, 5.0);
            } catch (DockerApiTimeout $e) {
                $this->failUnlessRunning($service, $e);
            }

            if ($samples === []) {
                $this->failUnlessRunning($service, new DockerApiUnreachable('The container service returned no resource sample.'));
            }

            return new Snapshot($this->calculator->compute($samples), CarbonImmutable::now('UTC'));
        });

        return ['containerName' => $service] + (array) $snapshot->payload;
    }

    /**
     * @throws ProblemException|DockerApiUnreachable always
     */
    private function failUnlessRunning(string $service, DockerApiUnreachable $failure): never
    {
        if (! ($this->containers->find($service, fresh: true)->running ?? false)) {
            $this->containers->forget($service);

            throw self::notRunning($service);
        }

        throw $failure;
    }

    private static function notRunning(string $service): ProblemException
    {
        return new ProblemException(ProblemType::ContainerNotRunning, "The container {$service} is not running.", ['container' => $service]);
    }
}
