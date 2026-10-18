<?php

namespace Tests\Feature\DnsDiagnostics;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Dkim\DkimFixtures;
use Tests\Support\DnsDiagnostics\DiagnosticsTestHelpers;
use Tests\Support\Smtp\FakeSmtpProbe;
use Tests\TestCase;

/**
 * Feature 010 T057 (SC-006, FR-042 to FR-044, spec edge cases): a zone that yields every status and
 * every reason and note code of `DnsCheckMessageCode`, each with its parameters.
 */
class DnsDiagnosticsStatusesTest extends TestCase
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

    /**
     * @return array<string, array<string, mixed>>
     */
    private function runChecks(): array
    {
        return self::checksById($this->diagnose('example.org')->assertOk());
    }

    public function test_a_resolver_failure_is_a_lookup_error_not_missing(): void
    {
        $this->dns()->failing('example.org', 'MX');

        $mx = $this->runChecks()['mx'];

        $this->assertSame(['lookupFailed', 'lookupError', [], []], [$mx['status'], $mx['reason']['code'], $mx['reason']['params'], $mx['found']]);
    }

    public function test_the_host_name_published_as_an_alias(): void
    {
        $this->dns()->cnameRecords(self::HOST, 'edge.example.net');

        $checks = $this->runChecks();

        $this->assertSame(['mismatch', 'hostIsAlias', ['target' => 'edge.example.net']], [$checks['a']['status'], $checks['a']['reason']['code'], $checks['a']['reason']['params']]);
        $this->assertSame('hostIsAlias', $checks['aaaa']['reason']['code']);
    }

    public function test_address_records_instead_of_an_alias_on_a_server_without_ipv6(): void
    {
        $this->server(null);
        $this->dns()->cnameRecords('autodiscover.example.org')->aRecords('autodiscover.example.org', self::IPV4);

        $response = $this->diagnose('example.org')->assertOk()->assertJsonPath('data.publicIpv6', null);
        $checks = self::checksById($response);

        $this->assertArrayNotHasKey('aaaa', $checks);
        $this->assertArrayNotHasKey('ptr:ipv6', $checks);
        $this->assertSame('match', $checks['autodiscover']['status']);
        $this->assertSame([['code' => 'matchedByAddressRecords', 'params' => ['ipv4' => [self::IPV4], 'ipv6' => []]]], array_map(fn (array $n): array => ['code' => $n['code'], 'params' => $n['params']], $checks['autodiscover']['notes']));
        $this->assertContains('noPublicIpv6', array_column((array) $response->json('data.notes'), 'code'));
        // Without IPv6, SPF needs to authorise the IPv4 address only.
        $this->dns()->txtRecords('example.org', 'v=spf1 ip4:'.self::IPV4.' -all');
        $this->assertSame('match', self::checksById($this->diagnose('example.org'))['spf']['status']);
    }

    public function test_a_null_service_record_is_the_expected_value(): void
    {
        $this->dns()->srvRecords('_pop3._tcp.example.org', [['.', 0]])->srvRecords('_pop3s._tcp.example.org', [['.', 0, 0, 0]]);

        $checks = $this->runChecks();

        foreach (['srv:pop3', 'srv:pop3s'] as $id) {
            $this->assertSame(['match', '. 0', ['. 0'], 'nullServiceRecord'], [$checks[$id]['status'], $checks[$id]['expected'], $checks[$id]['found'], $checks[$id]['notes'][0]['code']], $id);
        }
    }

    public function test_an_unknown_public_address(): void
    {
        config(['restv2.dns.public_ipv4' => '', 'restv2.dns.public_ipv6' => '']);
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => Http::failedConnection());

        $response = $this->diagnose('example.org')->assertOk()->assertJsonPath('data.publicIpv4', null);
        $checks = self::checksById($response);

        foreach (['a', 'ptr:ipv4', 'spf'] as $id) {
            $this->assertSame(['expectedUnknown', 'publicAddressUnknown', ['family' => 'ipv4']], [$checks[$id]['status'], $checks[$id]['reason']['code'], $checks[$id]['reason']['params']], $id);
        }

        $this->assertSame([self::IPV4], $checks['a']['found']);
    }

    /**
     * @return array<string, array{string, string|null, string}>
     */
    public static function certificateFailures(): array
    {
        return [
            'connection refused' => ['connect', 'connectionRefused', 'connectionFailed'],
            'connection timeout' => ['connect', 'timeout', 'timeout'],
            'silent greeting' => ['greeting', 'timeout', 'timeout'],
            'no STARTTLS' => ['tls', 'starttlsNotOffered', 'starttlsRefused'],
            'STARTTLS refused' => ['tls', 'rejected', 'starttlsRefused'],
            'handshake failed' => ['tls', 'tlsFailed', 'tlsHandshakeFailed'],
            'no certificate' => ['tls', null, 'noCertificate'],
        ];
    }

    #[DataProvider('certificateFailures')]
    public function test_an_unreadable_certificate_still_lists_the_tlsa_records(string $step, ?string $reason, string $cause): void
    {
        $this->smtp()->tlsCertificate($reason === null ? FakeSmtpProbe::certificate(null) : FakeSmtpProbe::tlsFailure($step, $reason));

        $tlsa = $this->runChecks()['tlsa:25'];

        $this->assertSame(['expectedUnknown', null, [self::tlsaDigest()]], [$tlsa['status'], $tlsa['expected'], $tlsa['found']]);
        $this->assertSame(['certificateUnavailable', ['host' => self::HOST, 'port' => 25, 'cause' => $cause]], [$tlsa['reason']['code'], $tlsa['reason']['params']]);
    }

    public function test_the_certificate_probe_uses_starttls_on_port_25_with_a_five_second_limit(): void
    {
        $this->runChecks();

        $request = $this->smtp()->assertProbed();
        $this->assertSame([self::HOST, 25, true, true, self::HOST], [$request->host, $request->port, $request->stopAfterTls, $request->capturePeerCertificate, $request->heloName]);
        $this->assertLessThanOrEqual(5.0, $request->totalTimeout);

        // The digest is reused: a second run does not probe again.
        $this->runChecks();
        $this->smtp()->assertProbed();
    }

    public function test_an_unavailable_store_makes_the_dkim_check_unknown(): void
    {
        $this->storeUnavailable();

        $dkim = $this->runChecks()['dkim'];

        $this->assertSame(['_domainkey.example.org', 'expectedUnknown', 'dkimKeyUnavailable'], [$dkim['recordName'], $dkim['status'], $dkim['reason']['code']]);
    }

    /**
     * @return array<string, array{list<string>, string, array<string, mixed>}>
     */
    public static function spfPolicies(): array
    {
        return [
            'not pass' => [['v=spf1 ip4:'.self::IPV4.' ~all'], 'spfNotPass', ['address' => self::IPV6, 'result' => 'softfail']],
            'two records' => [['v=spf1 a -all', 'v=spf1 mx -all'], 'spfMultipleRecords', ['count' => 2]],
            'too many lookups' => [['v=spf1 '.implode(' ', array_map(fn (int $i): string => "a:h{$i}.example.net", range(1, 11))).' -all'], 'spfTooManyLookups', ['limit' => 10]],
            'too many void lookups' => [['v=spf1 a:v1.example.net a:v2.example.net a:v3.example.net -all'], 'spfTooManyVoidLookups', ['limit' => 2]],
            'syntax error' => [['v=spf1 ip6:nonsense -all'], 'spfError', ['result' => 'permerror']],
        ];
    }

    /**
     * @param  list<string>  $records
     * @param  array<string, mixed>  $params
     */
    #[DataProvider('spfPolicies')]
    public function test_each_spf_reason(array $records, string $code, array $params): void
    {
        for ($i = 1; $i <= 11; $i++) {
            $this->dns()->aRecords("h{$i}.example.net", '198.51.100.'.$i);
        }
        $this->dns()->txtRecords('example.org', ...$records);

        $spf = $this->runChecks()['spf'];

        $this->assertSame(['mismatch', $code, $params, true], [$spf['status'], $spf['reason']['code'], $spf['reason']['params'], $spf['optional']]);
    }

    public function test_dmarc_and_tls_reporting_without_records_are_missing_and_optional(): void
    {
        $this->mailcow()->mtaSts('example.org', id: '20261018123000');
        $this->dns()->txtRecords('_dmarc.example.org');

        $checks = $this->runChecks();

        $this->assertSame(['missing', true], [$checks['dmarc']['status'], $checks['dmarc']['optional']]);
        $this->assertSame(['missing', true, ['tlsReportingPublishRecord']], [$checks['tls-rpt']['status'], $checks['tls-rpt']['optional'], array_column($checks['tls-rpt']['guidance'], 'code')]);
        $this->assertSame(['missing', 'missing'], [$checks['mta-sts']['status'], $checks['mta-sts-record']['status']]);
    }

    public function test_a_wrong_service_record_and_a_wrong_reverse_name_are_mismatches(): void
    {
        $this->dns()->srvRecords('_autodiscover._tcp.example.org', [[self::HOST, 8443]])->ptrRecords('10.2.0.192.in-addr.arpa', 'host.provider.example');

        $checks = $this->runChecks();

        $this->assertSame(['mismatch', [self::HOST.' 8443']], [$checks['srv:autodiscover']['status'], $checks['srv:autodiscover']['found']]);
        $this->assertSame(['mismatch', ['host.provider.example']], [$checks['ptr:ipv4']['status'], $checks['ptr:ipv4']['found']]);
    }
}
