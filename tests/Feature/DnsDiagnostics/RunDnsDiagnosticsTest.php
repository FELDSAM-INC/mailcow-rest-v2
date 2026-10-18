<?php

namespace Tests\Feature\DnsDiagnostics;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\Dkim\DkimFixtures;
use Tests\Support\DnsDiagnostics\DiagnosticsTestHelpers;
use Tests\TestCase;

/**
 * Feature 010 T056 (US3, FR-039 to FR-047): the structured result of a run, its checks with stable
 * identifiers and coded messages, and its zone file. A run changes nothing and is not audited.
 */
class RunDnsDiagnosticsTest extends TestCase
{
    use DiagnosticsTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
        $this->mailcow()->aliasDomain('alias.example', 'example.org');
        $this->mailcow()->dkimKey('example.org', 'dkim', 1024);
        $this->server();
        $this->healthyServer();
        $this->healthyDomain('example.org', DkimFixtures::RSA_1024_PUBLIC);
        $this->asAdmin();
    }

    public function test_the_full_result_of_a_healthy_domain(): void
    {
        $response = $this->diagnose('example.org')->assertOk()
            ->assertHeader('Cache-Control', 'no-store')
            ->assertJsonPath('data.name', 'example.org')
            ->assertJsonPath('data.displayName', 'example.org')
            ->assertJsonPath('data.aliasDomain', false)
            ->assertJsonPath('data.targetDomain', null)
            ->assertJsonPath('data.serverHostname', self::HOST)
            ->assertJsonPath('data.publicIpv4', self::IPV4)
            ->assertJsonPath('data.publicIpv6', self::IPV6)
            ->assertJsonPath('data.suggestedSpfRecord', null)
            ->assertJsonPath('data.serverChecksIncluded', true);

        $data = (array) $response->json('data');
        $checks = self::checksById($response);

        $this->assertSame(['a', 'ptr:ipv4', 'aaaa', 'ptr:ipv6', 'tlsa:25', 'mx', 'autodiscover', 'srv:autodiscover', 'autoconfig', 'spf', 'dmarc', 'dkim:dkim'], array_keys($checks));
        foreach ($checks as $id => $check) {
            $this->assertContains($check['status'], ['match', 'informational'], "{$id}: ".json_encode($check));
        }
        $this->assertSame(['match' => 11, 'mismatch' => 0, 'missing' => 0, 'lookupFailed' => 0, 'informational' => 1, 'expectedUnknown' => 0], $data['summary']);
        $this->assertSame(array_sum($data['summary']), count($data['checks']));

        $this->assertSame([
            'id' => 'mx', 'scope' => 'domain', 'recordName' => 'example.org', 'recordType' => 'MX', 'status' => 'match',
            'optional' => false, 'expected' => self::HOST, 'found' => [self::HOST], 'guidance' => [], 'reason' => null, 'notes' => [],
        ], $checks['mx']);
        $this->assertSame('server', $checks['a']['scope']);
        $this->assertSame(['3 1 1 '.substr(self::tlsaDigest(), 6)], $checks['tlsa:25']['found']);
        $this->assertTrue($checks['spf']['optional']);
        $this->assertNull($checks['spf']['expected']);
        $this->assertSame(['spfAuthorizeAddresses', 'spfSyntaxReference'], array_column($checks['spf']['guidance'], 'code'));
        $this->assertSame(['ipv4' => self::IPV4, 'ipv6' => self::IPV6], $checks['spf']['guidance'][0]['params']);
        $this->assertNotSame('', $checks['spf']['guidance'][0]['message']);
        $this->assertSame('informational', $checks['dmarc']['status']);
        $this->assertSame(['v=DMARC1; p=reject'], $checks['dmarc']['found']);
        $this->assertSame([['code' => 'propagationDelay', 'params' => ['hours' => 24]]], array_map(fn (array $n): array => ['code' => $n['code'], 'params' => $n['params']], $data['notes']));
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(\.\d+)?Z$/', $data['checkedAt']);
        $this->assertStringStartsWith("\$ORIGIN example.org.\n", $data['zoneFile']);
    }

    public function test_a_domain_without_mx_records_reports_missing_not_optional(): void
    {
        $this->dns()->mxRecords('example.org', []);

        $mx = self::checksById($this->diagnose('example.org')->assertOk())['mx'];

        $this->assertSame(['missing', false, []], [$mx['status'], $mx['optional'], $mx['found']]);
    }

    public function test_a_dkim_record_with_another_key_is_a_mismatch_listing_what_was_found(): void
    {
        $this->dns()->txtRecords('dkim._domainkey.example.org', 'v=DKIM1;k=rsa;p='.DkimFixtures::RSA_2048_PUBLIC);

        $dkim = self::checksById($this->diagnose('example.org')->assertOk())['dkim:dkim'];

        $this->assertSame('mismatch', $dkim['status']);
        $this->assertSame(['v=DKIM1;k=rsa;p='.DkimFixtures::RSA_2048_PUBLIC], $dkim['found']);
        $this->assertSame('dkimPublicKeyDiffers', $dkim['reason']['code']);
        $this->assertSame('v=DKIM1;k=rsa;t=s;s=email;p='.DkimFixtures::RSA_1024_PUBLIC, $dkim['expected']);
    }

    public function test_an_alias_domain_expects_its_target_s_mta_sts_policy(): void
    {
        $this->mailcow()->mtaSts('example.org', id: '20261018123000');
        $this->healthyDomain('alias.example', null, '20261018123000');

        $response = $this->diagnose('alias.example')->assertOk()
            ->assertJsonPath('data.aliasDomain', true)
            ->assertJsonPath('data.targetDomain', 'example.org');
        $checks = self::checksById($response);

        $this->assertSame(['_mta-sts.alias.example', 'v=STSv1;id=20261018123000;', 'match'], [$checks['mta-sts-record']['recordName'], $checks['mta-sts-record']['expected'], $checks['mta-sts-record']['status']]);
        $this->assertSame('match', $checks['mta-sts']['status']);
        $this->assertArrayNotHasKey('dkim:dkim', $checks, 'The alias domain has no key of its own.');
    }

    public function test_every_check_carries_its_identifier_and_coded_messages(): void
    {
        $this->dns()->cnameRecords('autoconfig.example.org')->aRecords('autoconfig.example.org', self::IPV4)->aaaaRecords('autoconfig.example.org', self::IPV6);

        $response = $this->diagnose('example.org')->assertOk();

        foreach ((array) $response->json('data.checks') as $check) {
            $this->assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*(:[A-Za-z0-9._-]+)?$/', $check['id']);

            foreach ([...$check['guidance'], ...$check['notes'], ...($check['reason'] === null ? [] : [$check['reason']])] as $message) {
                $this->assertArrayHasKey('code', $message);
                $this->assertIsArray($message['params']);
            }
        }

        $autoconfig = self::checksById($response)['autoconfig'];
        $this->assertSame(['match', 'matchedByAddressRecords'], [$autoconfig['status'], $autoconfig['notes'][0]['code']]);
    }

    public function test_an_inactive_domain_is_diagnosed_like_an_active_one(): void
    {
        DB::table('domain')->where('domain', 'example.org')->update(['active' => 0]);

        $this->diagnose('example.org')->assertOk()->assertJsonPath('data.summary.match', 11);
    }

    public function test_the_zone_file_passes_named_checkzone(): void
    {
        $zone = (string) $this->diagnose('example.org')->assertOk()->json('data.zoneFile');
        $file = (string) tempnam(sys_get_temp_dir(), 'zone');
        file_put_contents($file, "\$TTL 3600\nexample.org. IN SOA ns1.example.net. hostmaster.example.org. 1 3600 600 86400 3600\nexample.org. IN NS ns1.example.net.\n".$zone);

        $process = new Process(['named-checkzone', '-i', 'local', 'example.org', $file]);
        $process->run();
        @unlink($file);

        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput()."\n".$zone);
    }

    public function test_a_run_changes_nothing_and_writes_no_audit_record(): void
    {
        $before = [DB::table('domain')->get()->toJson(), DB::table('alias_domain')->get()->toJson(), DB::table('mta_sts')->get()->toJson()];
        $keys = [$this->redis()->hGetAll('DKIM_PUB_KEYS'), $this->redis()->hGetAll('DKIM_SELECTORS'), $this->redis()->hGetAll('DKIM_PRIV_KEYS')];

        $this->diagnose('example.org')->assertOk();

        $this->assertSame($before, [DB::table('domain')->get()->toJson(), DB::table('alias_domain')->get()->toJson(), DB::table('mta_sts')->get()->toJson()]);
        $this->assertSame($keys, [$this->redis()->hGetAll('DKIM_PUB_KEYS'), $this->redis()->hGetAll('DKIM_SELECTORS'), $this->redis()->hGetAll('DKIM_PRIV_KEYS')]);
        $this->assertSame(0, $this->auditTotal());
    }

    public function test_the_suggested_spf_record_and_unicode_names(): void
    {
        config(['restv2.dns.suggested_spf_record' => 'v=spf1 mx a ~all']);
        $this->mailcow()->domain('xn--bcher-kva.example');

        $response = $this->diagnose('BÜCHER.example')->assertOk()
            ->assertJsonPath('data.name', 'xn--bcher-kva.example')
            ->assertJsonPath('data.displayName', 'bücher.example')
            ->assertJsonPath('data.suggestedSpfRecord', 'v=spf1 mx a ~all');

        $this->assertSame(['spfAuthorizeAddresses', 'spfSuggestedRecord', 'spfSyntaxReference'], array_column(self::checksById($response)['spf']['guidance'], 'code'));
    }

    public function test_an_unknown_name_is_not_found(): void
    {
        $this->mailcow()->dkimKey('unused.example');

        $this->diagnose('nothing.example')->assertNotFound();
        $this->diagnose('unused.example')->assertNotFound();
    }
}
