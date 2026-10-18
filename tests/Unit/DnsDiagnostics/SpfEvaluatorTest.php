<?php

namespace Tests\Unit\DnsDiagnostics;

use App\Clients\Dns\DnsResolver;
use App\Services\DnsDiagnostics\DnsComparison;
use App\Services\DnsDiagnostics\LookupGuard;
use App\Services\DnsDiagnostics\Spf\SpfEvaluator;
use App\Support\Clock\Clock;
use Tests\Support\Dns\FakeDnsResolver;
use Tests\TestCase;

/**
 * Feature 010 T050 (FR-043, FR-050, research R18): the SPF check evaluates RFC 7208 check_host()
 * for the server's public addresses with mlocati/spf-lib on feature 009's resolver, honouring
 * ranges, `include`, `redirect`, the 10-lookup and 2-void-lookup limits, and reports coded
 * reasons.
 */
class SpfEvaluatorTest extends TestCase
{
    private const IPV4 = '192.0.2.10';

    private const IPV6 = '2001:db8::10';

    private function dns(): FakeDnsResolver
    {
        $dns = $this->app->make(DnsResolver::class);
        $this->assertInstanceOf(FakeDnsResolver::class, $dns);

        return $dns;
    }

    private function evaluate(?string $ipv4 = self::IPV4, ?string $ipv6 = self::IPV6): DnsComparison
    {
        return $this->app->make(SpfEvaluator::class)->evaluate('example.org', $ipv4, $ipv6, 'mail.example.org', LookupGuard::start($this->app->make(Clock::class)));
    }

    /**
     * @return array{string, string|null, array<string, mixed>}
     *
     * @phpstan-impure
     */
    private function outcome(?string $ipv4 = self::IPV4, ?string $ipv6 = self::IPV6): array
    {
        $result = $this->evaluate($ipv4, $ipv6);

        return [$result->status, $result->reason?->code, $result->reason->params ?? []];
    }

    public function test_ranges_include_redirect_a_and_mx_pass_both_addresses(): void
    {
        $dns = $this->dns();

        $dns->txtRecords('example.org', 'v=spf1 ip4:192.0.2.0/24 ip6:2001:db8::/32 -all');
        $this->assertSame(['match', null, []], $this->outcome());
        $this->assertSame(['v=spf1 ip4:192.0.2.0/24 ip6:2001:db8::/32 -all'], $this->evaluate()->found);

        $dns->txtRecords('example.org', 'v=spf1 a -all')->aRecords('example.org', self::IPV4)->aaaaRecords('example.org', self::IPV6);
        $this->assertSame(['match', null, []], $this->outcome());

        $dns->txtRecords('example.org', 'v=spf1 mx -all')->mxRecords('example.org', ['mx.example.org' => 10])
            ->aRecords('mx.example.org', self::IPV4)->aaaaRecords('mx.example.org', self::IPV6);
        $this->assertSame(['match', null, []], $this->outcome());

        $dns->txtRecords('example.org', 'v=spf1 include:_spf.example.net -all')
            ->txtRecords('_spf.example.net', 'v=spf1 ip4:192.0.2.10 ip6:2001:db8::10 -all');
        $this->assertSame(['match', null, []], $this->outcome());

        $dns->txtRecords('example.org', 'v=spf1 redirect=_spf.example.net');
        $this->assertSame(['match', null, []], $this->outcome());
    }

    public function test_a_policy_that_does_not_pass_names_the_address_and_the_result(): void
    {
        $dns = $this->dns();

        $dns->txtRecords('example.org', 'v=spf1 ip4:192.0.2.10 -all');
        $this->assertSame(['mismatch', 'spfNotPass', ['address' => self::IPV6, 'result' => 'fail']], $this->outcome());

        $dns->txtRecords('example.org', 'v=spf1 ~all');
        $this->assertSame(['mismatch', 'spfNotPass', ['address' => self::IPV4, 'result' => 'softfail']], $this->outcome());

        $dns->txtRecords('example.org', 'v=spf1 ?all');
        $this->assertSame(['mismatch', 'spfNotPass', ['address' => self::IPV4, 'result' => 'neutral']], $this->outcome());
    }

    public function test_two_spf_records_are_a_mismatch(): void
    {
        $this->dns()->txtRecords('example.org', 'v=spf1 a -all', 'google-site-verification=x', 'v=spf1 mx -all');

        $this->assertSame(['mismatch', 'spfMultipleRecords', ['count' => 2]], $this->outcome());
        $this->assertSame(['v=spf1 a -all', 'v=spf1 mx -all'], $this->evaluate()->found);
    }

    public function test_no_spf_record_is_missing(): void
    {
        $this->dns()->txtRecords('example.org', 'v=spf10 not spf', 'other');

        $this->assertSame(['missing', null, []], $this->outcome());
    }

    public function test_the_lookup_limits(): void
    {
        $dns = $this->dns();
        $includes = [];

        for ($i = 1; $i <= 11; $i++) {
            $includes[] = "include:i{$i}.example.net";
            $dns->txtRecords("i{$i}.example.net", 'v=spf1 -all');
        }

        $dns->txtRecords('example.org', 'v=spf1 '.implode(' ', $includes).' -all');
        $this->assertSame(['mismatch', 'spfTooManyLookups', ['limit' => 10]], $this->outcome());

        $dns->txtRecords('example.org', 'v=spf1 a:void1.example.net a:void2.example.net a:void3.example.net -all');
        $this->assertSame(['mismatch', 'spfTooManyVoidLookups', ['limit' => 2]], $this->outcome());
    }

    public function test_a_syntax_error_is_a_permanent_error(): void
    {
        $this->dns()->txtRecords('example.org', 'v=spf1 ip4:not-an-address -all');

        $this->assertSame(['mismatch', 'spfError', ['result' => 'permerror']], $this->outcome());
    }

    public function test_a_resolver_failure_during_the_evaluation_is_a_failed_lookup(): void
    {
        $dns = $this->dns();
        $dns->txtRecords('example.org', 'v=spf1 include:broken.example.net -all')->failing('broken.example.net');

        $this->assertSame(['lookupFailed', 'lookupError', []], $this->outcome());

        $dns->delay(4.0, 'broken.example.net');
        $this->assertSame(['lookupFailed', 'lookupTimeout', ['timeoutSeconds' => 5]], $this->outcome());

        $dns->failing('example.org', 'TXT');
        $dns->delay(0.0, 'broken.example.net');
        $this->assertSame(['lookupFailed', 'lookupError', []], $this->outcome());
    }

    public function test_without_a_public_ipv4_address_the_expected_value_is_unknown(): void
    {
        $this->dns()->txtRecords('example.org', 'v=spf1 a -all');

        $this->assertSame(['expectedUnknown', 'publicAddressUnknown', ['family' => 'ipv4']], $this->outcome(null, self::IPV6));
    }

    public function test_without_ipv6_only_the_ipv4_address_is_evaluated(): void
    {
        $this->dns()->txtRecords('example.org', 'v=spf1 ip4:192.0.2.10 -all');

        $this->assertSame(['match', null, []], $this->outcome(self::IPV4, null));
    }

    public function test_the_guidance(): void
    {
        $codes = fn (array $messages): array => array_map(fn ($m) => [$m->code, $m->params], $messages);

        $this->assertSame([
            ['spfAuthorizeAddresses', ['ipv4' => self::IPV4, 'ipv6' => null]],
            ['spfSyntaxReference', ['url' => 'http://www.open-spf.org/SPF_Record_Syntax/']],
        ], $codes(SpfEvaluator::guidance(self::IPV4, null)));

        config(['restv2.dns.suggested_spf_record' => 'v=spf1 mx a ~all']);
        $this->assertSame([
            ['spfAuthorizeAddresses', ['ipv4' => self::IPV4, 'ipv6' => self::IPV6]],
            ['spfSuggestedRecord', ['record' => 'v=spf1 mx a ~all']],
            ['spfSyntaxReference', ['url' => 'http://www.open-spf.org/SPF_Record_Syntax/']],
        ], $codes(SpfEvaluator::guidance(self::IPV4, self::IPV6)));
    }
}
