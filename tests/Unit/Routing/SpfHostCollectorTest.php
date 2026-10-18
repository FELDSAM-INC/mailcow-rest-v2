<?php

namespace Tests\Unit\Routing;

use App\Clients\Dns\DnsTemporaryFailure;
use App\Services\Routing\SpfHostCollector;
use PHPUnit\Framework\TestCase;
use Tests\Support\Dns\FakeDnsResolver;

/**
 * Feature 009 T087 (FR-076, research R16): the hosts an SPF record permits, as legacy's
 * get_outgoing_hosts_best_guess() collects them, with the lookup limit of 10 and the CIDR forms of
 * `a` and `mx`.
 */
class SpfHostCollectorTest extends TestCase
{
    private FakeDnsResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dns = new FakeDnsResolver;
    }

    /**
     * @return list<string>
     */
    private function collect(string $domain, int $limit = 10): array
    {
        return (new SpfHostCollector($this->dns, $limit))->collect($domain);
    }

    public function test_ip4_ip6_and_their_prefixes(): void
    {
        $this->dns->txtRecords('f.example', 'v=spf1 ip4:198.51.100.0/24 ip4:192.0.2.7 ip6:2001:db8::/32 -all');

        $this->assertSame(['198.51.100.0/24', '192.0.2.7', '2001:db8::/32'], $this->collect('f.example'));
    }

    public function test_a_and_mx_with_their_cidr_forms(): void
    {
        $this->dns->txtRecords('f.example', 'v=spf1 a a:other.example/24 a:dual.example/24//64 mx mx:mail.example -all')
            ->aRecords('f.example', '192.0.2.1')
            ->aRecords('other.example', '192.0.2.200')
            ->aRecords('dual.example', '198.51.100.9')->aaaaRecords('dual.example', '2001:db8::9')
            ->mxRecords('f.example', ['mx1.f.example' => 10])->aRecords('mx1.f.example', '203.0.113.1')
            ->mxRecords('mail.example', ['mx.mail.example' => 5])->aaaaRecords('mx.mail.example', '2001:db8:1::25');

        $this->assertSame(['192.0.2.1', '192.0.2.200/24', '198.51.100.9/24', '2001:db8::9/64', '203.0.113.1', '2001:db8:1::25'], $this->collect('f.example'));
    }

    public function test_include_adds_the_included_hosts(): void
    {
        $this->dns->txtRecords('forwarder.example', 'v=spf1 ip4:198.51.100.0/24 include:other.example ~all')
            ->txtRecords('other.example', 'v=spf1 ip4:203.0.113.7 -all');

        $this->assertSame(['198.51.100.0/24', '203.0.113.7'], $this->collect('forwarder.example'));
    }

    public function test_fail_and_softfail_end_their_record_and_other_qualifiers_are_stripped(): void
    {
        $this->dns->txtRecords('f.example', 'v=spf1 +ip4:192.0.2.1 ?ip4:192.0.2.2 include:sub.example -ip4:192.0.2.3 ip4:192.0.2.4')
            ->txtRecords('sub.example', 'v=spf1 ip4:198.51.100.1 ~ip4:198.51.100.2 ip4:198.51.100.3');

        $this->assertSame(['192.0.2.1', '192.0.2.2', '198.51.100.1'], $this->collect('f.example'));
    }

    public function test_redirect_replaces_the_result_and_a_self_include_is_skipped(): void
    {
        $this->dns->txtRecords('f.example', 'v=spf1 ip4:192.0.2.1 include:f.example redirect=_spf.example')
            ->txtRecords('_spf.example', 'v=spf1 ip4:203.0.113.0/24 -all');

        $this->assertSame(['203.0.113.0/24'], $this->collect('f.example'));
    }

    public function test_other_terms_and_records_are_ignored(): void
    {
        $this->dns->txtRecords('f.example', 'google-site-verification=abc', 'V=SPF1 ptr exists:%{i}.x.example exp=e.example ip4:192.0.2.1 all');

        $this->assertSame(['192.0.2.1'], $this->collect('f.example'));
    }

    public function test_the_eleventh_lookup_is_not_evaluated(): void
    {
        $terms = [];

        foreach (range(1, 11) as $i) {
            $terms[] = "include:i{$i}.example";
            $this->dns->txtRecords("i{$i}.example", "v=spf1 ip4:192.0.2.{$i}");
        }

        $this->dns->txtRecords('f.example', 'v=spf1 '.implode(' ', $terms).' -all');

        $result = $this->collect('f.example');

        $this->assertCount(10, $result);
        $this->assertNotContains('192.0.2.11', $result);
    }

    public function test_a_looping_include_stops_at_the_limit(): void
    {
        $this->dns->txtRecords('a.example', 'v=spf1 ip4:192.0.2.1 include:b.example')
            ->txtRecords('b.example', 'v=spf1 ip4:192.0.2.2 include:a.example');

        $this->assertSame(['192.0.2.1', '192.0.2.2'], array_values(array_unique($this->collect('a.example'))));
        $this->assertLessThanOrEqual(11, $this->dns->lookupCount('TXT'));
    }

    public function test_no_spf_record_gives_no_hosts(): void
    {
        $this->dns->txtRecords('f.example', 'not spf');

        $this->assertSame([], $this->collect('f.example'));
    }

    public function test_a_temporary_failure_anywhere_is_raised(): void
    {
        $this->dns->txtRecords('f.example', 'v=spf1 include:broken.example')->failing('broken.example');

        $this->expectException(DnsTemporaryFailure::class);
        $this->collect('f.example');
    }
}
