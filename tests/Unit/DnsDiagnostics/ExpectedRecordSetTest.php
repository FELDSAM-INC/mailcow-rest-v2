<?php

namespace Tests\Unit\DnsDiagnostics;

use App\Services\Dkim\DkimName;
use App\Services\DnsDiagnostics\ExpectedCheck;
use App\Services\DnsDiagnostics\ExpectedRecordSet;
use App\Services\DnsDiagnostics\ServerFacts;
use Tests\Support\Dkim\DkimFixtures;
use Tests\Support\Redis\UnavailableSharedStore;
use Tests\TestCase;

/**
 * Feature 010 T051 (FR-040 to FR-042, research R17): the checks of a run in legacy's order with
 * their identifiers, record names, expected values and guidance, derived from the server's
 * configuration, the stored DKIM key and the MTA-STS policy that applies.
 */
class ExpectedRecordSetTest extends TestCase
{
    private const DOMAIN_CHECKS = ['mx', 'autodiscover', 'srv:autodiscover', 'autoconfig', 'spf', 'dmarc', 'srv:pop3', 'srv:pop3s'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
        $this->mailcow()->aliasDomain('alias.example', 'example.org');
    }

    private static function facts(?string $ipv6 = '2001:db8::10', ?string $ipv4 = '192.0.2.10', ?string $digest = '3 1 1 abcdef', ?string $cause = null): ServerFacts
    {
        return new ServerFacts('mail.example.org', $ipv4, $ipv6, 'mail.example.org', $digest, $cause);
    }

    /**
     * @return list<ExpectedCheck>
     */
    private function build(string $name = 'example.org', ?ServerFacts $facts = null, bool $serverChecks = true, int $httpsPort = 443): array
    {
        $kind = $name === 'alias.example'
            ? new DkimName($name, $name, DkimName::ALIAS_DOMAIN, 'example.org')
            : new DkimName($name, $name, DkimName::DOMAIN, null);

        return $this->app->make(ExpectedRecordSet::class)->build($kind, $facts ?? self::facts(), $serverChecks, $httpsPort);
    }

    /**
     * @param  list<ExpectedCheck>  $checks
     * @return array<string, ExpectedCheck>
     */
    private static function byId(array $checks): array
    {
        return array_combine(array_map(fn (ExpectedCheck $c): string => $c->id, $checks), $checks);
    }

    public function test_the_full_order_for_an_administrator(): void
    {
        $this->mailcow()->mtaSts('example.org', id: '20261018123000');
        $this->mailcow()->dkimKey('example.org', 'dkim', 1024);
        config(['restv2.mailcow_ports.IMAPS_PORT' => 10993, 'restv2.mailcow_ports.SIEVE_PORT' => 14190]);

        $checks = $this->build();

        $this->assertSame([
            'a', 'ptr:ipv4', 'aaaa', 'ptr:ipv6', 'tlsa:25',
            'mx', 'autodiscover', 'srv:autodiscover', 'autoconfig', 'mta-sts', 'mta-sts-record', 'tls-rpt', 'spf', 'dmarc', 'dkim:dkim',
            'srv:pop3', 'srv:pop3s', 'srv:imaps', 'srv:sieve',
        ], array_map(fn (ExpectedCheck $c): string => $c->id, $checks));

        $c = self::byId($checks);
        $this->assertSame(['server', 'mail.example.org', 'A', '192.0.2.10', false], [$c['a']->scope, $c['a']->recordName, $c['a']->recordType, $c['a']->expected, $c['a']->optional]);
        $this->assertSame(['10.2.0.192.in-addr.arpa', 'PTR', 'mail.example.org'], [$c['ptr:ipv4']->recordName, $c['ptr:ipv4']->recordType, $c['ptr:ipv4']->expected]);
        $this->assertSame(['mail.example.org', 'AAAA', '2001:0db8:0000:0000:0000:0000:0000:0010'], [$c['aaaa']->recordName, $c['aaaa']->recordType, $c['aaaa']->expected]);
        $this->assertSame('0.1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa', $c['ptr:ipv6']->recordName);
        $this->assertSame(['_25._tcp.mail.example.org', 'TLSA', '3 1 1 abcdef'], [$c['tlsa:25']->recordName, $c['tlsa:25']->recordType, $c['tlsa:25']->expected]);
        $this->assertSame(['domain', 'example.org', 'MX', 'mail.example.org'], [$c['mx']->scope, $c['mx']->recordName, $c['mx']->recordType, $c['mx']->expected]);
        $this->assertSame(['autodiscover.example.org', 'CNAME', 'mail.example.org'], [$c['autodiscover']->recordName, $c['autodiscover']->recordType, $c['autodiscover']->expected]);
        $this->assertSame(['_autodiscover._tcp.example.org', 'SRV', 'mail.example.org 443'], [$c['srv:autodiscover']->recordName, $c['srv:autodiscover']->recordType, $c['srv:autodiscover']->expected]);
        $this->assertSame(['mta-sts.example.org', 'CNAME', 'mail.example.org'], [$c['mta-sts']->recordName, $c['mta-sts']->recordType, $c['mta-sts']->expected]);
        $this->assertSame(['_mta-sts.example.org', 'TXT', 'v=STSv1;id=20261018123000;'], [$c['mta-sts-record']->recordName, $c['mta-sts-record']->recordType, $c['mta-sts-record']->expected]);
        $this->assertSame(['_smtp._tls.example.org', true, null], [$c['tls-rpt']->recordName, $c['tls-rpt']->optional, $c['tls-rpt']->expected]);
        $this->assertSame(['tlsReportingPublishRecord'], array_map(fn ($m) => $m->code, $c['tls-rpt']->guidance));
        $this->assertSame(['example.org', 'TXT', true, null], [$c['spf']->recordName, $c['spf']->recordType, $c['spf']->optional, $c['spf']->expected]);
        $this->assertSame(['spfAuthorizeAddresses', 'spfSyntaxReference'], array_map(fn ($m) => $m->code, $c['spf']->guidance));
        $this->assertSame(['_dmarc.example.org', true, ['dmarcPublishPolicy']], [$c['dmarc']->recordName, $c['dmarc']->optional, array_map(fn ($m) => $m->code, $c['dmarc']->guidance)]);
        $this->assertSame(['dkim._domainkey.example.org', 'TXT', 'v=DKIM1;k=rsa;t=s;s=email;p='.DkimFixtures::RSA_1024_PUBLIC, false], [$c['dkim:dkim']->recordName, $c['dkim:dkim']->recordType, $c['dkim:dkim']->expected, $c['dkim:dkim']->optional]);
        $this->assertSame(['_imaps._tcp.example.org', 'SRV', 'mail.example.org 10993'], [$c['srv:imaps']->recordName, $c['srv:imaps']->recordType, $c['srv:imaps']->expected]);
        $this->assertSame('mail.example.org 14190', $c['srv:sieve']->expected);
    }

    public function test_service_records_only_for_non_standard_ports_and_pop3_only_for_null_records(): void
    {
        $checks = self::byId($this->build());

        $this->assertArrayNotHasKey('srv:imap', $checks);
        $this->assertArrayNotHasKey('srv:submission', $checks);
        // POP3 and POP3S are looked up anyway: a null record (target `.`) is the expected value.
        $this->assertTrue($checks['srv:pop3']->nullServiceRecordAllowed);
        $this->assertTrue($checks['srv:pop3']->onlyWithNullServiceRecord);
        $this->assertTrue($checks['srv:pop3s']->onlyWithNullServiceRecord);
        $this->assertSame('mail.example.org 110', $checks['srv:pop3']->expected);

        config(['restv2.mailcow_ports.POP_PORT' => 1110, 'restv2.mailcow_ports.IMAP_PORT' => 1143, 'restv2.mailcow_ports.SUBMISSION_PORT' => 1587, 'restv2.mailcow_ports.SMTPS_PORT' => 1465]);
        $checks = self::byId($this->build());
        $this->assertFalse($checks['srv:pop3']->onlyWithNullServiceRecord);
        $this->assertSame('mail.example.org 1110', $checks['srv:pop3']->expected);
        $this->assertSame(['mail.example.org 1143', 'mail.example.org 1587', 'mail.example.org 1465'], [$checks['srv:imap']->expected, $checks['srv:submission']->expected, $checks['srv:smtps']->expected]);
    }

    public function test_the_autodiscover_port_follows_the_request(): void
    {
        $this->assertSame('mail.example.org 8443', self::byId($this->build(httpsPort: 8443))['srv:autodiscover']->expected);
    }

    public function test_mta_sts_checks_need_an_active_policy_and_an_alias_domain_uses_its_target_s(): void
    {
        $this->mailcow()->mtaSts('example.org', active: 0, id: '20261018123000');
        $this->assertArrayNotHasKey('mta-sts', self::byId($this->build()));

        \DB::table('mta_sts')->where('domain', 'example.org')->update(['active' => 1]);
        $alias = self::byId($this->build('alias.example'));
        $this->assertSame('mta-sts.alias.example', $alias['mta-sts']->recordName);
        $this->assertSame('v=STSv1;id=20261018123000;', $alias['mta-sts-record']->expected);
    }

    public function test_no_dkim_check_without_a_key_and_an_unknown_one_when_the_store_is_down(): void
    {
        $this->assertSame([], array_values(array_filter(array_keys(self::byId($this->build())), fn (string $id): bool => str_starts_with($id, 'dkim'))));

        UnavailableSharedStore::bind($this->app);
        $dkim = self::byId($this->build())['dkim'];

        $this->assertSame(['_domainkey.example.org', 'TXT', null, 'dkimKeyUnavailable'], [$dkim->recordName, $dkim->recordType, $dkim->expected, $dkim->unknownReason?->code]);
    }

    public function test_an_alias_domain_has_its_own_dkim_key(): void
    {
        $this->mailcow()->dkimKey('alias.example', 'alias', 1024);
        $this->mailcow()->dkimKey('example.org', 'dkim', 2048);

        $this->assertArrayHasKey('dkim:alias', self::byId($this->build('alias.example')));
    }

    public function test_without_ipv6_the_ipv6_checks_are_left_out(): void
    {
        $ids = array_keys(self::byId($this->build(facts: self::facts(null))));

        $this->assertSame(['a', 'ptr:ipv4', 'tlsa:25', 'mx'], array_slice($ids, 0, 4));
    }

    public function test_unknown_server_facts_make_their_checks_unknown(): void
    {
        $checks = self::byId($this->build(facts: self::facts(null, null, null, 'timeout')));

        $this->assertSame([null, 'publicAddressUnknown', ['family' => 'ipv4']], [$checks['a']->expected, $checks['a']->unknownReason?->code, $checks['a']->unknownReason->params ?? null]);
        $this->assertSame('publicAddressUnknown', $checks['ptr:ipv4']->unknownReason?->code);
        $this->assertSame(['certificateUnavailable', ['host' => 'mail.example.org', 'port' => 25, 'cause' => 'timeout']], [$checks['tlsa:25']->unknownReason?->code, $checks['tlsa:25']->unknownReason->params ?? null]);
    }

    public function test_domain_administrators_get_no_server_checks(): void
    {
        $ids = array_keys(self::byId($this->build(serverChecks: false)));

        $this->assertSame(self::DOMAIN_CHECKS, $ids);
    }
}
