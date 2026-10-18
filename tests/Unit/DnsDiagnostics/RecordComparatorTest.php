<?php

namespace Tests\Unit\DnsDiagnostics;

use App\Services\DnsDiagnostics\DnsComparison;
use App\Services\DnsDiagnostics\RecordComparator;
use PHPUnit\Framework\TestCase;

/**
 * Feature 010 T049 (FR-043, research R17): how found records are compared with the expected value,
 * and in which comparison form they are reported.
 */
class RecordComparatorTest extends TestCase
{
    private const DKIM = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAkey';

    /**
     * @return array{string, list<string>, string|null, list<string>}
     */
    private static function parts(DnsComparison $c): array
    {
        return [$c->status, $c->found, $c->reason?->code, array_map(fn ($n) => $n->code, $c->notes)];
    }

    public function test_host_names_ignore_case_and_the_trailing_dot(): void
    {
        $this->assertSame('mail.example.org', RecordComparator::host('MAIL.Example.org.'));
        $this->assertSame(['match', ['mail.example.org'], null, []], self::parts(RecordComparator::ptr('mail.example.org', ['MAIL.example.org.'])));
        $this->assertSame(['mismatch', ['other.example'], null, []], self::parts(RecordComparator::ptr('mail.example.org', ['other.example'])));
        $this->assertSame(['missing', [], null, []], self::parts(RecordComparator::ptr('mail.example.org', [])));
    }

    public function test_ipv6_addresses_are_compared_as_addresses_and_shown_expanded(): void
    {
        $this->assertSame('2001:0db8:0000:0000:0000:0000:0000:0010', RecordComparator::expandIpv6('2001:db8::10'));

        $c = RecordComparator::address('2001:db8::10', ['2001:DB8:0:0::10'], []);
        $this->assertSame(['match', ['2001:0db8:0000:0000:0000:0000:0000:0010'], null, []], self::parts($c));
        $this->assertSame(['match', ['192.0.2.10'], null, []], self::parts(RecordComparator::address('192.0.2.10', ['192.0.2.10'], [])));
        $this->assertSame(['mismatch', ['192.0.2.11'], null, []], self::parts(RecordComparator::address('192.0.2.10', ['192.0.2.11'], [])));
    }

    public function test_the_host_name_published_as_an_alias_is_a_mismatch(): void
    {
        $c = RecordComparator::address('192.0.2.10', ['192.0.2.10'], ['edge.example.net.']);

        $this->assertSame(['mismatch', ['192.0.2.10'], 'hostIsAlias', []], self::parts($c));
        $this->assertSame(['target' => 'edge.example.net'], $c->reason?->params);
    }

    public function test_tlsa_data_is_compared_case_insensitively(): void
    {
        $this->assertSame(['match', ['3 1 1 abcdef'], null, []], self::parts(RecordComparator::tlsa('3 1 1 ABCDEF', ['3 1 1 abcdef'])));
        $this->assertSame('mismatch', RecordComparator::tlsa('3 1 1 abcdef', ['3 1 1 012345'])->status);
    }

    public function test_mx_matches_when_any_exchange_is_the_host(): void
    {
        $c = RecordComparator::mx('mail.example.org', [['host' => 'backup.example.net', 'preference' => 20], ['host' => 'MAIL.example.org', 'preference' => 10]]);

        $this->assertSame(['match', ['backup.example.net', 'mail.example.org'], null, []], self::parts($c));
        $this->assertSame('mismatch', RecordComparator::mx('mail.example.org', [['host' => 'other.example', 'preference' => 10]])->status);
        $this->assertSame('missing', RecordComparator::mx('mail.example.org', [])->status);
    }

    public function test_cname_matches_the_host_or_falls_back_to_address_records(): void
    {
        $this->assertSame(['match', ['mail.example.org'], null, []], self::parts(RecordComparator::cname('mail.example.org', ['mail.example.org.'], [], [], [], [])));
        $this->assertSame(['mismatch', ['other.example'], null, []], self::parts(RecordComparator::cname('mail.example.org', ['other.example'], [], [], [], [])));

        $both = RecordComparator::cname('mail.example.org', [], ['192.0.2.10'], ['2001:db8::10'], ['192.0.2.10'], ['2001:db8:0::10']);
        $this->assertSame(['match', ['192.0.2.10', '2001:0db8:0000:0000:0000:0000:0000:0010'], null, ['matchedByAddressRecords']], self::parts($both));
        $this->assertSame(['ipv4' => ['192.0.2.10'], 'ipv6' => ['2001:0db8:0000:0000:0000:0000:0000:0010']], $both->notes[0]->params);

        // On a server without IPv6, equal IPv4 addresses are enough (legacy reported a mismatch).
        $ipv4Only = RecordComparator::cname('mail.example.org', [], ['192.0.2.10'], [], ['192.0.2.10'], []);
        $this->assertSame(['match', ['192.0.2.10'], null, ['matchedByAddressRecords']], self::parts($ipv4Only));

        $this->assertSame('mismatch', RecordComparator::cname('mail.example.org', [], ['192.0.2.10'], [], ['192.0.2.10'], ['2001:db8::10'])->status);
        $this->assertSame(['mismatch', ['192.0.2.99'], null, []], self::parts(RecordComparator::cname('mail.example.org', [], ['192.0.2.99'], [], ['192.0.2.10'], [])));
        $this->assertSame('missing', RecordComparator::cname('mail.example.org', [], [], [], ['192.0.2.10'], [])->status);
    }

    public function test_srv_compares_target_and_port_only(): void
    {
        $records = [['target' => 'MAIL.example.org.', 'port' => 993, 'priority' => 5, 'weight' => 0]];

        $this->assertSame(['match', ['mail.example.org 993'], null, []], self::parts(RecordComparator::srv('mail.example.org', 993, $records)));
        $this->assertSame('mismatch', RecordComparator::srv('mail.example.org', 10993, $records)->status);
        $this->assertSame('missing', RecordComparator::srv('mail.example.org', 993, [])->status);
        $this->assertSame(['mismatch', ['. 0'], null, []], self::parts(RecordComparator::srv('mail.example.org', 995, [['target' => '.', 'port' => 0, 'priority' => 0, 'weight' => 0]])));
    }

    public function test_dkim_compares_the_public_key_whatever_the_tag_order_and_spaces(): void
    {
        $expected = 'v=DKIM1;k=rsa;t=s;s=email;p='.self::DKIM;

        $this->assertSame('match', RecordComparator::dkim(self::DKIM, ['k=rsa; p = '.self::DKIM.' ; v=DKIM1'])->status);
        $this->assertSame('match', RecordComparator::dkim(self::DKIM, ['v=DKIM1; p='.substr(self::DKIM, 0, 20).' '.substr(self::DKIM, 20)])->status);

        $other = RecordComparator::dkim(self::DKIM, ['v=DKIM1;k=rsa;p=MIIBother']);
        $this->assertSame(['mismatch', ['v=DKIM1;k=rsa;p=MIIBother'], 'dkimPublicKeyDiffers', []], self::parts($other));
        $this->assertSame('missing', RecordComparator::dkim(self::DKIM, [])->status);
        $this->assertSame($expected, 'v=DKIM1;k=rsa;t=s;s=email;p='.self::DKIM);
    }

    public function test_the_mta_sts_record_compares_the_v_and_id_tags(): void
    {
        $this->assertSame('match', RecordComparator::mtaSts('20261018123000', ['v=STSv1; id=20261018123000;'])->status);
        $this->assertSame('match', RecordComparator::mtaSts('20261018123000', ['v = STSv1 ; id = 20261018123000'])->status);
        $this->assertSame('mismatch', RecordComparator::mtaSts('20261018123000', ['v=STSv1;id=20261018000000;'])->status);
        $this->assertSame(['missing', [], null, []], self::parts(RecordComparator::mtaSts('20261018123000', ['google-site-verification=abc'])));
    }

    public function test_dmarc_and_tls_reporting_are_informational_or_missing(): void
    {
        $this->assertSame(['informational', ['v=DMARC1; p=none'], null, []], self::parts(RecordComparator::informational('v=DMARC1', ['v=DMARC1; p=none', 'other'])));
        $this->assertSame('missing', RecordComparator::informational('v=DMARC1', ['other'])->status);
        $this->assertSame('informational', RecordComparator::informational('v=TLSRPTv1', ['V=TLSRPTv1; rua=mailto:tls@example.org'])->status);
    }
}
