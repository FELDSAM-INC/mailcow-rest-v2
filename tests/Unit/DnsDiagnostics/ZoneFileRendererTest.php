<?php

namespace Tests\Unit\DnsDiagnostics;

use App\Services\DnsDiagnostics\DnsCheckMessage;
use App\Services\DnsDiagnostics\DnsCheckResult;
use App\Services\DnsDiagnostics\ZoneFileRenderer;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Feature 010 T052 (FR-047, SC-007, research R21): the zone file of a run has an `$ORIGIN` line and
 * one line per record of the name with absolute owners, MX preference 10, SRV priority 0 and
 * weight 1, quoted and split TXT values, and commented placeholders for SPF, DMARC and TLS
 * reporting without values. Every rendering passes `named-checkzone` (BIND 9) behind a minimal SOA
 * and NS header, because a record list for import carries none (as legacy's).
 */
class ZoneFileRendererTest extends TestCase
{
    private const LONG_KEY = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA6ebjqiSHfgf9aldl8sqG5NWIkbV3h/slVCnGVmjTLRNhf5jFUfuLxIt4xvn7HJujSfR2EPGXKHuYGaVp1lsJxyZVasL31ks8DcF2QbkxCDvgaXEmBNSD4mlOwOWJ8DcTcsxQRx+s8QZGyvRo4XVguQYvO5r21n+hAqqXbkr5s6LI+Um3iqq9VEqH/7AIjUgg2uCxRLmqbc5cE/GKoADWm0ZxvIArgR10SWERPBpa/egthIvXsNIj9e95DLgANz4cfYE6f+/LQ/QJOS5uT7bx39lpAG8ZMrThVKbEdmhQdOU2K2mHO1EMsI9U43YsaLDiE2qNN/W6wQ+NnXbu9N+ZrQIDAQAB';

    private static function check(string $id, string $scope, string $name, string $type, string $status, ?string $expected, array $found = [], bool $optional = false): DnsCheckResult
    {
        return new DnsCheckResult($id, $scope, $name, $type, $status, $optional, $expected, $found, [], null, []);
    }

    /**
     * @return list<DnsCheckResult>
     */
    private static function checks(string $name, bool $withValues): array
    {
        $dkim = 'v=DKIM1;k=rsa;t=s;s=email;p='.self::LONG_KEY;

        return [
            self::check('a', 'server', 'mail.example.org', 'A', 'match', '192.0.2.10', ['192.0.2.10']),
            self::check('ptr:ipv4', 'server', '10.2.0.192.in-addr.arpa', 'PTR', 'match', 'mail.example.org'),
            self::check('tlsa:25', 'server', '_25._tcp.mail.example.org', 'TLSA', 'match', '3 1 1 abcdef0123'),
            self::check('mx', 'domain', $name, 'MX', 'missing', 'mail.example.org'),
            self::check('autodiscover', 'domain', "autodiscover.{$name}", 'CNAME', 'match', 'mail.example.org'),
            self::check('srv:autodiscover', 'domain', "_autodiscover._tcp.{$name}", 'SRV', 'mismatch', 'mail.example.org 443'),
            self::check('autoconfig', 'domain', "autoconfig.{$name}", 'CNAME', 'match', 'mail.example.org'),
            self::check('mta-sts', 'domain', "mta-sts.{$name}", 'CNAME', 'match', 'mail.example.org'),
            self::check('mta-sts-record', 'domain', "_mta-sts.{$name}", 'TXT', 'match', 'v=STSv1;id=20261018123000;'),
            self::check('tls-rpt', 'domain', "_smtp._tls.{$name}", 'TXT', $withValues ? 'informational' : 'missing', null, $withValues ? ['v=TLSRPTv1; rua=mailto:tls@example.org'] : [], true),
            self::check('spf', 'domain', $name, 'TXT', $withValues ? 'match' : 'missing', null, $withValues ? ['v=spf1 mx a include:"odd\\name" ~all'] : [], true),
            self::check('dmarc', 'domain', "_dmarc.{$name}", 'TXT', $withValues ? 'informational' : 'missing', null, $withValues ? ['v=DMARC1; p=reject; rua=mailto:dmarc@example.org'] : [], true),
            self::check('dkim:dkim', 'domain', "dkim._domainkey.{$name}", 'TXT', 'missing', $dkim),
            self::check('srv:pop3', 'domain', "_pop3._tcp.{$name}", 'SRV', 'match', '. 0'),
            self::check('srv:imaps', 'domain', "_imaps._tcp.{$name}", 'SRV', 'missing', 'mail.example.org 10993'),
        ];
    }

    public function test_the_rendering(): void
    {
        $zone = (new ZoneFileRenderer)->render('example.org', self::checks('example.org', true));
        $lines = explode("\n", trim($zone));

        $this->assertSame('$ORIGIN example.org.', $lines[0]);
        $this->assertContains("example.org.\tIN\tMX\t10 mail.example.org.", $lines);
        $this->assertContains("autodiscover.example.org.\tIN\tCNAME\tmail.example.org.", $lines);
        $this->assertContains("_autodiscover._tcp.example.org.\tIN\tSRV\t0 1 443 mail.example.org.", $lines);
        $this->assertContains("_mta-sts.example.org.\tIN\tTXT\t\"v=STSv1;id=20261018123000;\"", $lines);
        $this->assertContains("example.org.\tIN\tTXT\t\"v=spf1 mx a include:\\\"odd\\\\name\\\" ~all\"", $lines);
        $this->assertContains("_pop3._tcp.example.org.\tIN\tSRV\t0 0 0 .", $lines);
        $this->assertContains("_imaps._tcp.example.org.\tIN\tSRV\t0 1 10993 mail.example.org.", $lines);

        $dkim = (string) collect($lines)->first(fn (string $l): bool => str_starts_with($l, 'dkim._domainkey.'));
        preg_match_all('/"([^"]*)"/', $dkim, $strings);
        $this->assertGreaterThan(1, count($strings[1]));
        $this->assertSame('v=DKIM1;k=rsa;t=s;s=email;p='.self::LONG_KEY, implode('', $strings[1]));
        $this->assertLessThanOrEqual(255, max(array_map('strlen', $strings[1])));

        // Server-wide records are left out, also the host name inside the domain.
        $this->assertStringNotContainsString('in-addr.arpa', $zone);
        $this->assertStringNotContainsString('_25._tcp', $zone);
        $this->assertStringNotContainsString("mail.example.org.\tIN\tA", $zone);
    }

    public function test_placeholders_for_spf_dmarc_and_tls_reporting_without_values(): void
    {
        config(['restv2.dns.suggested_spf_record' => 'v=spf1 mx a ~all']);

        $zone = (new ZoneFileRenderer)->render('example.org', self::checks('example.org', false));

        $this->assertStringContainsString("; example.org.\tIN\tTXT\t\"v=spf1 mx a ~all\"", $zone);
        $this->assertMatchesRegularExpression('/^; _dmarc\.example\.org\.\tIN\tTXT\t"v=DMARC1; p=.*"$/m', $zone);
        $this->assertMatchesRegularExpression('/^; _smtp\._tls\.example\.org\.\tIN\tTXT\t"v=TLSRPTv1; rua=.*"$/m', $zone);
    }

    public function test_checks_without_an_expected_value_are_left_out(): void
    {
        $unknown = new DnsCheckResult('dkim', 'domain', '_domainkey.example.org', 'TXT', 'expectedUnknown', false, null, [], [], DnsCheckMessage::dkimKeyUnavailable(), []);

        $zone = (new ZoneFileRenderer)->render('example.org', [$unknown]);

        $this->assertSame("\$ORIGIN example.org.\n", $zone);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function matrix(): array
    {
        return [
            'domain with values' => ['example.org', true],
            'domain without values' => ['example.org', false],
            'idn domain' => ['xn--bcher-kva.example', true],
            'single label' => ['localdomain', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('matrix')]
    public function test_every_rendering_passes_named_checkzone(string $name, bool $withValues): void
    {
        config(['restv2.dns.suggested_spf_record' => $withValues ? '' : 'v=spf1 mx a ~all']);
        $zone = (new ZoneFileRenderer)->render($name, self::checks($name, $withValues));
        $file = tempnam(sys_get_temp_dir(), 'zone');
        file_put_contents($file, "\$TTL 3600\n{$name}. IN SOA ns1.{$name}. hostmaster.{$name}. 1 3600 600 86400 3600\n{$name}. IN NS ns1.example.net.\n".$zone);

        $process = new Process(['named-checkzone', '-i', 'local', $name, $file]);
        $process->run();
        @unlink($file);

        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput()."\n".$zone);
        $this->assertStringContainsString('OK', $process->getOutput());
    }
}
