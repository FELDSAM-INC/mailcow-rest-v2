<?php

namespace Tests\Feature\DnsDiagnostics;

use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Dkim\DkimFixtures;
use Tests\Support\DnsDiagnostics\DiagnosticsTestHelpers;
use Tests\TestCase;

/**
 * Feature 010 T102 (SC-006): with every lookup answering after 20 ms (fake clock), 20 runs of a
 * healthy domain finish within 5 s at p95 of their run time; with every lookup timing out, each run
 * returns within the 30-second deadline.
 */
#[Group('performance')]
class DnsDiagnosticsPerformanceTest extends TestCase
{
    use DiagnosticsTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        config(['restv2.dns.runs_per_minute' => 100]);
        $this->mailcow()->domain('example.org');
        $this->mailcow()->dkimKey('example.org', 'dkim', 1024);
        $this->server();
        $this->healthyServer();
        $this->healthyDomain('example.org', DkimFixtures::RSA_1024_PUBLIC);
        $this->asAdmin();
    }

    /**
     * Run time per run: the fake clock's advance plus the real time spent.
     *
     * @return list<float>
     */
    private function runs(int $count): array
    {
        $durations = [];

        for ($i = 0; $i < $count; $i++) {
            $fake = $this->clock()->now()->getTimestampMs();
            $real = microtime(true);
            $this->diagnose('example.org')->assertOk();
            $durations[] = ($this->clock()->now()->getTimestampMs() - $fake) / 1000 + (microtime(true) - $real);
        }

        sort($durations);

        return $durations;
    }

    public function test_healthy_runs_finish_within_five_seconds_at_the_95th_percentile(): void
    {
        $this->dns()->delay(0.02);

        $durations = $this->runs(20);

        $this->assertLessThan(5.0, $durations[(int) ceil(0.95 * count($durations)) - 1]);
    }

    public function test_runs_against_unresponsive_name_servers_end_within_the_deadline(): void
    {
        $this->dns()->delay(4.0);

        foreach (['example.org', 'autodiscover.example.org', '_autodiscover._tcp.example.org', 'autoconfig.example.org', '_dmarc.example.org', 'dkim._domainkey.example.org', '_pop3._tcp.example.org', '_pop3s._tcp.example.org', self::HOST, '10.2.0.192.in-addr.arpa', self::IPV6_PTR, '_25._tcp.'.self::HOST] as $name) {
            $this->dns()->failing($name);
        }

        foreach ($this->runs(3) as $duration) {
            $this->assertLessThanOrEqual(30.0 + 1.0, $duration);
        }
    }
}
