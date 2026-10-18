<?php

namespace Tests\Support\DnsDiagnostics;

use App\Clients\Dns\DnsResolver;
use App\Clients\Smtp\SmtpProbe;
use App\Services\DnsDiagnostics\TlsaDigest;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Dkim\DkimFixtures;
use Tests\Support\Dkim\DkimTestHelpers;
use Tests\Support\Dns\FakeDnsResolver;
use Tests\Support\Smtp\FakeSmtpProbe;

/**
 * The server, zone and callers of the DNS diagnostics tests (feature 010 US3). The public addresses
 * are configured, so that no test asks the echo service; DNS and the SMTP certificate probe are the
 * fakes that Tests\TestCase binds.
 */
trait DiagnosticsTestHelpers
{
    use DkimTestHelpers;

    protected const HOST = 'mail.example.org';

    protected const IPV4 = '192.0.2.10';

    protected const IPV6 = '2001:db8::10';

    protected const IPV6_PTR = '0.1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa';

    protected function dns(): FakeDnsResolver
    {
        $dns = $this->app->make(DnsResolver::class);
        $this->assertInstanceOf(FakeDnsResolver::class, $dns);

        return $dns;
    }

    protected function smtp(): FakeSmtpProbe
    {
        $smtp = $this->app->make(SmtpProbe::class);
        $this->assertInstanceOf(FakeSmtpProbe::class, $smtp);

        return $smtp;
    }

    /**
     * The server: host name, public addresses and the certificate presented on port 25.
     */
    protected function server(?string $ipv6 = self::IPV6): void
    {
        config(['restv2.dns.public_ipv4' => self::IPV4, 'restv2.dns.public_ipv6' => $ipv6 ?? 'none']);
        $this->smtp()->tlsCertificate(FakeSmtpProbe::certificate(DkimFixtures::CERTIFICATE));
    }

    protected static function tlsaDigest(): string
    {
        return (string) TlsaDigest::fromCertificate(DkimFixtures::CERTIFICATE);
    }

    /**
     * Every record a correctly configured server and domain publish.
     */
    protected function healthyServer(): void
    {
        $this->dns()
            ->aRecords(self::HOST, self::IPV4)
            ->aaaaRecords(self::HOST, self::IPV6)
            ->ptrRecords('10.2.0.192.in-addr.arpa', self::HOST)
            ->ptrRecords(self::IPV6_PTR, self::HOST)
            ->tlsaRecords('_25._tcp.'.self::HOST, self::tlsaDigest());
    }

    /**
     * Every record a correctly configured domain publishes (DKIM with the given public key).
     */
    protected function healthyDomain(string $name, ?string $dkimPublicKey = null, ?string $mtaStsId = null): void
    {
        $dns = $this->dns()
            ->mxRecords($name, [self::HOST => 10])
            ->cnameRecords("autodiscover.{$name}", self::HOST)
            ->srvRecords("_autodiscover._tcp.{$name}", [[self::HOST, 443]])
            ->cnameRecords("autoconfig.{$name}", self::HOST)
            ->txtRecords($name, 'v=spf1 ip4:'.self::IPV4.' ip6:'.self::IPV6.' -all')
            ->txtRecords("_dmarc.{$name}", 'v=DMARC1; p=reject');

        if ($dkimPublicKey !== null) {
            $dns->txtRecords("dkim._domainkey.{$name}", 'v=DKIM1;k=rsa;t=s;s=email;p='.$dkimPublicKey);
        }

        if ($mtaStsId !== null) {
            $dns->cnameRecords("mta-sts.{$name}", self::HOST)
                ->txtRecords("_mta-sts.{$name}", "v=STSv1;id={$mtaStsId};")
                ->txtRecords("_smtp._tls.{$name}", 'v=TLSRPTv1; rua=mailto:tls@example.org');
        }
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    protected function diagnose(string $name, array $headers = []): TestResponse
    {
        return $this->getJson('/api/v2/dns-diagnostics/'.rawurlencode($name), $headers);
    }

    /**
     * The checks of a result by identifier.
     *
     * @param  TestResponse<Response>  $response
     * @return array<string, array<string, mixed>>
     */
    protected static function checksById(TestResponse $response): array
    {
        $checks = [];

        foreach ((array) $response->json('data.checks') as $check) {
            $checks[$check['id']] = $check;
        }

        return $checks;
    }
}
