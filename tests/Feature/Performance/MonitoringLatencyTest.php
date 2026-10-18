<?php

namespace Tests\Feature\Performance;

use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\Support\Redis\MailcowLogs;
use Tests\TestCase;

/**
 * Feature 011 T108 (SC-003, research R31): the overview, the container list and a page of 100 out
 * of 10,000 log entries answer within 1 second in 95% of requests, with the recorded payloads and
 * the fakes (the container service answers immediately). Wall-clock budgets fail under heavy load:
 * rerun this class alone before treating a failure as real.
 */
#[Group('performance')]
class MonitoringLatencyTest extends TestCase
{
    use MonitoringTestHelpers;

    private const ITERATIONS = 20;

    private const BUDGET = 1.0;

    /**
     * The 95th percentile of the request durations, in seconds.
     */
    private function p95(string $uri): float
    {
        $durations = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $start = hrtime(true);
            $this->getJson($uri)->assertOk();
            $durations[] = (hrtime(true) - $start) / 1e9;
        }

        sort($durations);

        return $durations[(int) ceil(0.95 * count($durations)) - 1];
    }

    public function test_the_overview_and_the_container_list(): void
    {
        $this->asAdmin();

        $this->assertLessThan(self::BUDGET, $this->p95('/api/v2/host'));
        $this->assertLessThan(self::BUDGET, $this->p95('/api/v2/containers?limit=100'));
    }

    public function test_a_page_of_a_full_in_memory_log(): void
    {
        $lines = [];

        for ($i = 0; $i < 10000; $i++) {
            $lines[] = MailcowLogs::syslogLine(1760781600 + $i, $i % 7 === 0 ? 'warning' : 'info', 'postfix/smtpd', "connect from unknown[192.0.2.{$i}] to=<user{$i}@example.org>");
        }

        MailcowLogs::rawMany('POSTFIX_MAILLOG', $lines);
        $this->asAdmin();

        $this->assertLessThan(self::BUDGET, $this->p95('/api/v2/logs/postfix?limit=100'));
    }
}
