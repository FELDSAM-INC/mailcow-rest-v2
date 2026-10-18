<?php

namespace App\Services\Monitoring;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Counters and rates of a container from dockerapi's raw `docker stats` samples (feature 011
 * research R7), computed like the `docker stats` CLI from the newest sample: processor percent from
 * its `cpu_stats` and `precpu_stats`, memory without the inactive file cache, block I/O `Read` and
 * `Write` (cgroup v1 and v2) and network bytes summed over all interfaces. Rates are computed
 * against the newest earlier sample at least one second older; counters that went back (a
 * restarted container) give no rate.
 */
class DockerStatsCalculator
{
    /**
     * @param  list<array<string, mixed>>  $samples
     * @return array{sampledAt: string, previousSampledAt: string|null, cpuPercent: float|null, memory: array{usedBytes: int, limitBytes: int}, disk: array{readBytes: int, writtenBytes: int, readBytesPerSecond: float|null, writeBytesPerSecond: float|null}, network: array{receivedBytes: int, sentBytes: int, receiveBytesPerSecond: float|null, sendBytesPerSecond: float|null}}
     */
    public function compute(array $samples): array
    {
        $timed = [];

        foreach ($samples as $sample) {
            $time = self::time($sample['read'] ?? null);

            if ($time !== null) {
                $timed[] = ['time' => $time, 'sample' => $sample];
            }
        }

        usort($timed, fn (array $a, array $b): int => $a['time']->getTimestampMs() <=> $b['time']->getTimestampMs());
        $newest = array_pop($timed) ?? ['time' => CarbonImmutable::now('UTC'), 'sample' => []];
        $previous = null;

        foreach (array_reverse($timed) as $candidate) {
            if (self::seconds($candidate['time'], $newest['time']) >= 1.0) {
                $previous = $candidate;

                break;
            }
        }

        $now = self::counters($newest['sample']);
        $before = $previous === null ? null : self::counters($previous['sample']);
        $elapsed = $previous === null ? 0.0 : self::seconds($previous['time'], $newest['time']);
        $rate = fn (string $counter): ?float => $before === null || $now[$counter] < $before[$counter]
            ? null
            : ($now[$counter] - $before[$counter]) / $elapsed;

        return [
            'sampledAt' => $newest['time']->format('Y-m-d\TH:i:s\Z'),
            'previousSampledAt' => $previous === null ? null : $previous['time']->format('Y-m-d\TH:i:s\Z'),
            'cpuPercent' => self::cpuPercent($newest['sample']),
            'memory' => self::memory($newest['sample']),
            'disk' => [
                'readBytes' => $now['read'],
                'writtenBytes' => $now['write'],
                'readBytesPerSecond' => $rate('read'),
                'writeBytesPerSecond' => $rate('write'),
            ],
            'network' => [
                'receivedBytes' => $now['rx'],
                'sentBytes' => $now['tx'],
                'receiveBytesPerSecond' => $rate('rx'),
                'sendBytesPerSecond' => $rate('tx'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $sample
     * @return array{read: int, write: int, rx: int, tx: int}
     */
    private static function counters(array $sample): array
    {
        $read = $write = $rx = $tx = 0;

        foreach (is_array($sample['blkio_stats']['io_service_bytes_recursive'] ?? null) ? $sample['blkio_stats']['io_service_bytes_recursive'] : [] as $entry) {
            $op = is_array($entry) ? strtolower((string) ($entry['op'] ?? '')) : '';
            $value = is_array($entry) ? max(0, (int) ($entry['value'] ?? 0)) : 0;
            $read += $op === 'read' ? $value : 0;
            $write += $op === 'write' ? $value : 0;
        }

        foreach (is_array($sample['networks'] ?? null) ? $sample['networks'] : [] as $interface) {
            $rx += is_array($interface) ? max(0, (int) ($interface['rx_bytes'] ?? 0)) : 0;
            $tx += is_array($interface) ? max(0, (int) ($interface['tx_bytes'] ?? 0)) : 0;
        }

        return ['read' => $read, 'write' => $write, 'rx' => $rx, 'tx' => $tx];
    }

    /**
     * @param  array<string, mixed>  $sample
     */
    private static function cpuPercent(array $sample): ?float
    {
        $cpu = (float) ($sample['cpu_stats']['cpu_usage']['total_usage'] ?? 0);
        $preCpu = (float) ($sample['precpu_stats']['cpu_usage']['total_usage'] ?? 0);
        $system = (float) ($sample['cpu_stats']['system_cpu_usage'] ?? 0);
        $preSystem = (float) ($sample['precpu_stats']['system_cpu_usage'] ?? 0);
        $online = (int) ($sample['cpu_stats']['online_cpus'] ?? 0);

        if ($online <= 0) {
            $online = max(1, count((array) ($sample['cpu_stats']['cpu_usage']['percpu_usage'] ?? [])));
        }

        $cpuDelta = $cpu - $preCpu;
        $systemDelta = $system - $preSystem;

        return $cpuDelta > 0 && $systemDelta > 0 ? round($cpuDelta / $systemDelta * $online * 100, 2) : null;
    }

    /**
     * @param  array<string, mixed>  $sample
     * @return array{usedBytes: int, limitBytes: int}
     */
    private static function memory(array $sample): array
    {
        $stats = is_array($sample['memory_stats']['stats'] ?? null) ? $sample['memory_stats']['stats'] : [];
        $cache = $stats['inactive_file'] ?? $stats['total_inactive_file'] ?? $stats['cache'] ?? 0;

        return [
            'usedBytes' => max(0, (int) ($sample['memory_stats']['usage'] ?? 0) - (int) $cache),
            'limitBytes' => max(0, (int) ($sample['memory_stats']['limit'] ?? 0)),
        ];
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || str_starts_with($value, '0001-01-01')) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) preg_replace('/(\.\d{6})\d+/', '$1', $value))->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private static function seconds(CarbonImmutable $from, CarbonImmutable $to): float
    {
        return ($to->getTimestampMs() - $from->getTimestampMs()) / 1000;
    }
}
