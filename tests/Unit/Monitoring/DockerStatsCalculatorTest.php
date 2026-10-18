<?php

namespace Tests\Unit\Monitoring;

use App\Services\Monitoring\DockerStatsCalculator;
use PHPUnit\Framework\TestCase;
use Tests\Support\Monitoring\MonitoringFixtures;

/**
 * Feature 011 T028 (FR-011, research R7): counters and rates from dockerapi's `docker stats`
 * samples, computed like the `docker stats` CLI.
 */
class DockerStatsCalculatorTest extends TestCase
{
    public function test_one_sample_gives_counters_and_no_rates(): void
    {
        $usage = (new DockerStatsCalculator)->compute(MonitoringFixtures::stats('stats-postfix-1.json'));

        $this->assertSame('2026-10-18T10:00:00Z', $usage['sampledAt']);
        $this->assertNull($usage['previousSampledAt']);
        $this->assertEqualsWithDelta(10.0, $usage['cpuPercent'], 0.0001);
        $this->assertSame(['usedBytes' => 104857600, 'limitBytes' => 16777216000], $usage['memory']);
        $this->assertSame(['readBytes' => 1000000, 'writtenBytes' => 4000000, 'readBytesPerSecond' => null, 'writeBytesPerSecond' => null], $usage['disk']);
        $this->assertSame(['receivedBytes' => 10001000, 'sentBytes' => 2001000, 'receiveBytesPerSecond' => null, 'sendBytesPerSecond' => null], $usage['network']);
    }

    public function test_three_samples_give_rates_between_the_newest_two(): void
    {
        $usage = (new DockerStatsCalculator)->compute(MonitoringFixtures::stats('stats-postfix-3.json'));

        $this->assertSame('2026-10-18T10:00:10Z', $usage['sampledAt']);
        $this->assertSame('2026-10-18T10:00:05Z', $usage['previousSampledAt']);
        $this->assertEqualsWithDelta(12.0, $usage['cpuPercent'], 0.0001);
        $this->assertSame(2500000, $usage['disk']['readBytes']);
        $this->assertSame(5000000, $usage['disk']['writtenBytes']);
        $this->assertEqualsWithDelta(200000.0, $usage['disk']['readBytesPerSecond'], 0.001);
        $this->assertEqualsWithDelta(200000.0, $usage['disk']['writeBytesPerSecond'], 0.001);
        $this->assertSame(11001000, $usage['network']['receivedBytes']);
        $this->assertEqualsWithDelta(100000.0, $usage['network']['receiveBytesPerSecond'], 0.001);
        $this->assertEqualsWithDelta(30000.0, $usage['network']['sendBytesPerSecond'], 0.001);
    }

    public function test_the_order_of_the_samples_does_not_matter(): void
    {
        $samples = array_reverse(MonitoringFixtures::stats('stats-postfix-3.json'));

        $this->assertSame('2026-10-18T10:00:10Z', (new DockerStatsCalculator)->compute($samples)['sampledAt']);
    }

    public function test_cgroup_v1_counters(): void
    {
        $usage = (new DockerStatsCalculator)->compute(MonitoringFixtures::stats('stats-cgroup-v1.json'));

        $this->assertSame(1800000, $usage['disk']['readBytes'], 'Sync, Async and Total entries are not counted');
        $this->assertSame(4400000, $usage['disk']['writtenBytes']);
        $this->assertEqualsWithDelta(200000.0, $usage['disk']['readBytesPerSecond'], 0.001);
        $this->assertEqualsWithDelta(100000.0, $usage['disk']['writeBytesPerSecond'], 0.001);
        $this->assertSame(104857600, $usage['memory']['usedBytes'], 'total_inactive_file is subtracted');
    }

    public function test_missing_networks_and_block_io_count_zero(): void
    {
        $sample = MonitoringFixtures::stats('stats-postfix-1.json')[0];
        unset($sample['networks']);
        $sample['blkio_stats']['io_service_bytes_recursive'] = null;

        $usage = (new DockerStatsCalculator)->compute([$sample]);

        $this->assertSame(0, $usage['network']['receivedBytes']);
        $this->assertSame(0, $usage['network']['sentBytes']);
        $this->assertSame(0, $usage['disk']['readBytes']);
    }

    public function test_cpu_is_null_when_the_deltas_are_not_positive(): void
    {
        $sample = MonitoringFixtures::stats('stats-postfix-1.json')[0];
        $sample['precpu_stats'] = $sample['cpu_stats'];

        $this->assertNull((new DockerStatsCalculator)->compute([$sample])['cpuPercent']);
    }

    public function test_samples_less_than_one_second_apart_give_no_rates(): void
    {
        [$first] = MonitoringFixtures::stats('stats-postfix-1.json');
        $second = $first;
        $second['read'] = '2026-10-18T10:00:00.500000000Z';

        $usage = (new DockerStatsCalculator)->compute([$first, $second]);

        $this->assertNull($usage['previousSampledAt']);
        $this->assertNull($usage['disk']['readBytesPerSecond']);
    }

    public function test_counters_that_went_back_give_no_rate(): void
    {
        [$first, $second] = MonitoringFixtures::stats('stats-postfix-3.json');
        $second['networks']['eth0']['rx_bytes'] = 5;

        $usage = (new DockerStatsCalculator)->compute([$first, $second]);

        $this->assertNull($usage['network']['receiveBytesPerSecond'], 'a restarted container resets its counters');
    }
}
