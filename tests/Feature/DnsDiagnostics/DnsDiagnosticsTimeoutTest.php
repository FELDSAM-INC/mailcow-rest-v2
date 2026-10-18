<?php

namespace Tests\Feature\DnsDiagnostics;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Dkim\DkimFixtures;
use Tests\Support\DnsDiagnostics\DiagnosticsTestHelpers;
use Tests\TestCase;

/**
 * Feature 010 T058 (US3 AS4, FR-048, research R16): with the fake clock, a lookup that reaches the
 * resolver's bound (about 4 s) reports `lookupTimeout`, a fast failure `lookupError`; a lookup
 * starts only while at least RESTV2_DNS_LOOKUP_TIMEOUT (5 s) of the 30-second run remain, the
 * rest reports `runTimeout` without a lookup, and the run still answers `200` with every check.
 */
class DnsDiagnosticsTimeoutTest extends TestCase
{
    use DiagnosticsTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
        $this->mailcow()->dkimKey('example.org', 'dkim', 1024);
        $this->server();
        $this->healthyServer();
        $this->healthyDomain('example.org', DkimFixtures::RSA_1024_PUBLIC);
        $this->asAdmin();
    }

    public function test_unresponsive_name_servers_fail_each_lookup_and_the_run_stops_at_its_deadline(): void
    {
        $started = $this->clock()->now();
        $this->dns()->delay(4.0)->failing('example.org')->failing('autodiscover.example.org')->failing('_autodiscover._tcp.example.org')
            ->failing('autoconfig.example.org')->failing('_dmarc.example.org')->failing('dkim._domainkey.example.org')
            ->failing(self::HOST)->failing('10.2.0.192.in-addr.arpa')->failing(self::IPV6_PTR)->failing('_25._tcp.'.self::HOST);

        $response = $this->diagnose('example.org')->assertOk();
        $checks = self::checksById($response);

        $this->assertSame(['a', 'ptr:ipv4', 'aaaa', 'ptr:ipv6', 'tlsa:25', 'mx', 'autodiscover', 'srv:autodiscover', 'autoconfig', 'spf', 'dmarc', 'dkim:dkim', 'srv:pop3', 'srv:pop3s'], array_keys($checks));

        $reasons = array_map(fn (array $c): string => $c['status'].'/'.$c['reason']['code'], $checks);
        $timedOut = array_keys(array_filter($reasons, fn (string $r): bool => $r === 'lookupFailed/lookupTimeout'));
        $notStarted = array_keys(array_filter($reasons, fn (string $r): bool => $r === 'lookupFailed/runTimeout'));
        $this->assertSame(count($checks), count($timedOut) + count($notStarted), json_encode($reasons) ?: '');
        $this->assertNotEmpty($timedOut);
        $this->assertNotEmpty($notStarted);
        $this->assertSame(['timeoutSeconds' => 5], $checks[$timedOut[0]]['reason']['params']);
        $this->assertSame(['timeoutSeconds' => 30], $checks[$notStarted[0]]['reason']['params']);
        $this->assertSame(count($checks), $response->json('data.summary.lookupFailed'));

        // Every lookup started while at least 5 s were left: at most 25 s of lookups, and the run
        // ended within its 30 seconds.
        $elapsed = ($this->clock()->now()->getTimestampMs() - $started->getTimestampMs()) / 1000;
        $this->assertLessThanOrEqual(30.0, $elapsed);
        $this->assertLessThanOrEqual(intdiv(25, 4) + 1, $this->dns()->lookupCount());
    }

    public function test_a_fast_failure_is_a_lookup_error(): void
    {
        $this->dns()->failing('example.org', 'MX');

        $this->assertSame('lookupError', self::checksById($this->diagnose('example.org'))['mx']['reason']['code']);
    }

    public function test_a_slow_failure_is_a_lookup_timeout(): void
    {
        $this->dns()->failing('example.org', 'MX')->delay(4.0, 'example.org');

        $mx = self::checksById($this->diagnose('example.org'))['mx'];

        $this->assertSame(['lookupTimeout', ['timeoutSeconds' => 5]], [$mx['reason']['code'], $mx['reason']['params']]);
    }

    public function test_the_echo_service_and_the_certificate_fetch_give_up_after_five_seconds(): void
    {
        config(['restv2.dns.public_ipv4' => '', 'restv2.dns.public_ipv6' => '']);
        $timeouts = [];
        Http::preventStrayRequests();
        Http::fake(function (Request $request, array $options) use (&$timeouts) {
            $timeouts[] = [$options['timeout'] ?? null, $options['connect_timeout'] ?? null];

            return Http::response(str_contains($request->url(), 'ip4') ? self::IPV4 : self::IPV6);
        });

        $this->diagnose('example.org')->assertOk();

        $this->assertCount(2, $timeouts);
        foreach ($timeouts as [$total, $connect]) {
            $this->assertLessThanOrEqual(5, $total);
            $this->assertLessThanOrEqual(5, $connect);
        }
        $this->assertLessThanOrEqual(5.0, $this->smtp()->assertProbed()->totalTimeout);
    }
}
