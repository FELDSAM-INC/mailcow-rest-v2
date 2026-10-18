<?php

namespace App\Services\Monitoring;

use App\Clients\DockerApi\DockerApiClient;
use App\Clients\DockerApi\DockerApiUnreachable;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The system overview (feature 011 research R6, FR-005, FR-006): dockerapi's host figures, shared
 * by all clients and served while at most 10 seconds old, judged by dockerapi's `system_time`
 * (local time in mailcow's `TZ`), plus the host name, time zone, installed version and the current
 * time.
 */
class HostOverviewService
{
    public function __construct(
        private readonly DockerApiClient $docker,
        private readonly SharedSnapshotCache $cache,
        private readonly InstallationVersionService $version,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws DockerApiUnreachable
     */
    public function overview(): array
    {
        $snapshot = $this->cache->get('host', (int) config('restv2.monitoring.max_age.host', 10), 6.0, function (): Snapshot {
            $stats = $this->docker->hostStats();

            return new Snapshot($stats, self::systemTime($stats['system_time'] ?? null) ?? CarbonImmutable::now('UTC'));
        });

        $stats = (array) $snapshot->payload;
        $swap = is_array($stats['memory']['swap'] ?? null) ? array_values($stats['memory']['swap']) : [];

        return [
            'hostName' => (string) (config('restv2.mailcow_hostname') ?? ''),
            'timeZone' => self::zone(),
            'architecture' => is_string($stats['architecture'] ?? null) ? $stats['architecture'] : '',
            'version' => $this->version->current()->toArray(),
            'currentTime' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'uptimeSeconds' => max(0, (int) floor((float) ($stats['uptime'] ?? 0))),
            'cpu' => [
                'cores' => max(1, (int) ($stats['cpu']['cores'] ?? 1)),
                'usagePercent' => self::percent($stats['cpu']['usage'] ?? 0),
            ],
            'memory' => [
                'totalBytes' => max(0, (int) ($stats['memory']['total'] ?? 0)),
                'usagePercent' => self::percent($stats['memory']['usage'] ?? 0),
            ],
            'swap' => [
                'totalBytes' => max(0, (int) ($swap[0] ?? 0)),
                'usedBytes' => max(0, (int) ($swap[1] ?? 0)),
                'usagePercent' => self::percent($swap[3] ?? 0),
            ],
            'collectedAt' => $snapshot->collectedAtString(),
        ];
    }

    /** dockerapi's `dd.mm.YYYY HH:MM:SS` in mailcow's time zone, in UTC. */
    private static function systemTime(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        try {
            $time = CarbonImmutable::createFromFormat('d.m.Y H:i:s', $value, self::zone());

            return $time instanceof CarbonImmutable ? $time->utc() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function percent(mixed $value): float
    {
        return min(100.0, max(0.0, round((float) $value, 2)));
    }

    private static function zone(): string
    {
        return (string) (config('restv2.timezone') ?: 'UTC');
    }
}
