<?php

namespace Tests\Unit\DnsDiagnostics;

use App\Clients\Dns\PublicAddressDetector;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Feature 010 T055 (FR-042, FR-049, research R19): the server's public addresses come from
 * mailcow's address echo service, asked once over IPv4 and once over IPv6 with legacy's URLs and a
 * 5-second limit, unless the operator configured them. Answers are reused for
 * RESTV2_DNS_FACTS_TTL seconds; failures are not reused.
 */
class PublicAddressDetectorTest extends TestCase
{
    /** @var array<string, string> url => body; other URLs fail to connect */
    private array $answers = [];

    /** @var list<array{url: string, options: array<string, mixed>}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake(function (Request $request, array $options) {
            $this->sent[] = ['url' => rtrim($request->url(), '/'), 'options' => $options];
            $answer = $this->answers[rtrim($request->url(), '/')] ?? null;

            return $answer === null ? Http::failedConnection() : Http::response($answer);
        });
    }

    /**
     * @return array{ipv4: string|null, ipv6: string|null}
     */
    private function detect(): array
    {
        return $this->app->make(PublicAddressDetector::class)->detect();
    }

    public function test_both_families_are_asked_with_a_forced_address_family(): void
    {
        $this->answers = ['http://ip4.mailcow.email' => "192.0.2.10\n", 'http://ip6.mailcow.email' => '2001:db8::10'];

        $this->assertSame(['ipv4' => '192.0.2.10', 'ipv6' => '2001:db8::10'], $this->detect());

        $this->assertSame(['http://ip4.mailcow.email', 'http://ip6.mailcow.email'], array_column($this->sent, 'url'));
        $this->assertSame(['v4', 'v6'], array_map(fn (array $s): mixed => $s['options']['force_ip_resolve'] ?? null, $this->sent));
        $this->assertLessThanOrEqual(5, $this->sent[0]['options']['timeout']);
        $this->assertLessThanOrEqual(3, $this->sent[0]['options']['connect_timeout']);
    }

    public function test_invalid_answers_are_unknown_and_a_missing_ipv6_answer_means_no_ipv6(): void
    {
        $this->answers = ['http://ip4.mailcow.email' => '<html>busy</html>', 'http://ip6.mailcow.email' => '192.0.2.10'];
        $this->assertSame(['ipv4' => null, 'ipv6' => null], $this->detect());

        $this->answers = ['http://ip4.mailcow.email' => '192.0.2.10'];
        $this->assertSame(['ipv4' => '192.0.2.10', 'ipv6' => null], $this->detect());
    }

    public function test_configured_addresses_win_without_a_request(): void
    {
        config(['restv2.dns.public_ipv4' => '198.51.100.7', 'restv2.dns.public_ipv6' => '2001:db8::7']);
        $this->assertSame(['ipv4' => '198.51.100.7', 'ipv6' => '2001:db8::7'], $this->detect());

        config(['restv2.dns.public_ipv6' => 'none']);
        $this->assertSame(['ipv4' => '198.51.100.7', 'ipv6' => null], $this->detect());
        $this->assertSame([], $this->sent);
    }

    public function test_a_configured_ipv4_address_asks_only_for_ipv6(): void
    {
        $this->answers = ['http://ip6.mailcow.email' => '2001:db8::10'];
        config(['restv2.dns.public_ipv4' => '198.51.100.7']);

        $this->assertSame(['ipv4' => '198.51.100.7', 'ipv6' => '2001:db8::10'], $this->detect());
        $this->assertSame(['http://ip6.mailcow.email'], array_column($this->sent, 'url'));
    }

    public function test_the_echo_urls_are_settings(): void
    {
        config(['restv2.dns.echo_url_ipv4' => 'http://echo4.example.net', 'restv2.dns.echo_url_ipv6' => 'http://echo6.example.net']);
        $this->answers = ['http://echo4.example.net' => '192.0.2.10'];

        $this->assertSame(['ipv4' => '192.0.2.10', 'ipv6' => null], $this->detect());
    }

    public function test_answers_are_reused_for_the_facts_lifetime_and_failures_are_not(): void
    {
        config(['restv2.dns.facts_ttl' => 600]);
        $redis = Redis::connection('restv2');

        $this->assertSame(['ipv4' => null, 'ipv6' => null], $this->detect());
        $this->assertFalse((bool) $redis->exists('dns-facts:addresses'));

        $this->answers = ['http://ip4.mailcow.email' => '192.0.2.10'];
        $this->assertSame(['ipv4' => '192.0.2.10', 'ipv6' => null], $this->detect());
        $ttl = (int) $redis->ttl('dns-facts:addresses');
        $this->assertGreaterThan(590, $ttl);
        $this->assertLessThanOrEqual(600, $ttl);
        $stored = json_decode((string) $redis->get('dns-facts:addresses'), true);
        $this->assertSame(['192.0.2.10', null], [$stored['ipv4'], $stored['ipv6']]);
        $this->assertArrayHasKey('detectedAt', $stored);

        $count = count($this->sent);
        $this->answers = ['http://ip4.mailcow.email' => '192.0.2.99'];
        $this->assertSame(['ipv4' => '192.0.2.10', 'ipv6' => null], $this->detect());
        $this->assertCount($count, $this->sent);
    }
}
