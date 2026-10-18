<?php

namespace App\Services\DnsDiagnostics;

use App\Clients\SharedStore\DkimKeyStore;
use App\Clients\SharedStore\SharedStoreUnavailable;
use App\Models\Mailcow\MtaStsPolicy;
use App\Services\Config\MailcowConfig;
use App\Services\Dkim\DkimName;
use App\Services\Dkim\DkimPublicKey;
use App\Services\DnsDiagnostics\Spf\SpfEvaluator;
use App\Services\Mailcow\AutodiscoverConfig;

/**
 * The checks of a DNS diagnostics run in legacy's order (feature 010 FR-040, FR-041, research R17,
 * legacy dns_diagnostics.php):
 *
 *  - for administrators first the server-wide checks: the host name's A record and the public
 *    IPv4 address's reverse name, with a public IPv6 address its AAAA record and reverse name, and
 *    the TLSA record of SMTP on port 25;
 *  - then the name's MX, autodiscover (CNAME and SRV with the web interface's HTTPS port),
 *    autoconfig, MTA-STS (host, record and TLS reporting) while the policy that applies is active
 *    (for an alias domain its target domain's), SPF, DMARC, DKIM when the name has a key with a
 *    selector and a public key (unknown when the shared store cannot be read), and the SRV records
 *    of non-standard service ports (POP3 and POP3S also for a null record).
 *
 * Expected values come from the server facts, `$autodiscover_config`, the stored DKIM key and the
 * `mta_sts` row. Legacy meant to leave the client-configuration records out for alias domains, but
 * its condition never holds, so alias domains get them too.
 */
class ExpectedRecordSet
{
    public function __construct(
        private readonly DkimKeyStore $store,
        private readonly MailcowConfig $config,
    ) {}

    /**
     * @return list<ExpectedCheck>
     */
    public function build(DkimName $name, ServerFacts $facts, bool $serverChecks, int $httpsPort): array
    {
        $host = $facts->hostname;
        $ports = AutodiscoverConfig::resolve($this->config);
        $checks = $serverChecks ? $this->serverChecks($facts) : [];
        $domain = $name->name;

        $checks[] = new ExpectedCheck('mx', ExpectedCheck::DOMAIN, $domain, 'MX', 'mx', $host);
        $checks[] = new ExpectedCheck('autodiscover', ExpectedCheck::DOMAIN, "autodiscover.{$domain}", 'CNAME', 'cname', $host);
        $checks[] = self::srv('srv:autodiscover', "_autodiscover._tcp.{$domain}", $host, $httpsPort);
        $checks[] = new ExpectedCheck('autoconfig', ExpectedCheck::DOMAIN, "autoconfig.{$domain}", 'CNAME', 'cname', $host);

        $policy = MtaStsPolicy::query()->find($name->targetDomain ?? $domain);

        if ($policy instanceof MtaStsPolicy && $policy->active) {
            $checks[] = new ExpectedCheck('mta-sts', ExpectedCheck::DOMAIN, "mta-sts.{$domain}", 'CNAME', 'cname', $host);
            $checks[] = new ExpectedCheck('mta-sts-record', ExpectedCheck::DOMAIN, "_mta-sts.{$domain}", 'TXT', 'mtaSts', "v=STSv1;id={$policy->policy_id};", data: ['policyId' => $policy->policy_id]);
            $checks[] = new ExpectedCheck('tls-rpt', ExpectedCheck::DOMAIN, "_smtp._tls.{$domain}", 'TXT', 'tlsReporting', null, true, [DnsCheckMessage::tlsReportingPublishRecord()]);
        }

        $checks[] = new ExpectedCheck('spf', ExpectedCheck::DOMAIN, $domain, 'TXT', 'spf', null, true, SpfEvaluator::guidance($facts->ipv4, $facts->ipv6));
        $checks[] = new ExpectedCheck('dmarc', ExpectedCheck::DOMAIN, "_dmarc.{$domain}", 'TXT', 'dmarc', null, true, [DnsCheckMessage::dmarcPublishPolicy()]);

        $dkim = $this->dkimCheck($domain);

        if ($dkim !== null) {
            $checks[] = $dkim;
        }

        $checks[] = self::srv('srv:pop3', "_pop3._tcp.{$domain}", $ports->pop3Host, $ports->pop3Port, 110, true);
        $checks[] = self::srv('srv:pop3s', "_pop3s._tcp.{$domain}", $ports->pop3Host, $ports->pop3sPort, 995, true);

        foreach ([
            ['srv:imap', "_imap._tcp.{$domain}", $ports->imapHost, $ports->imapPort, 143],
            ['srv:imaps', "_imaps._tcp.{$domain}", $ports->imapHost, $ports->imapsPort, 993],
            ['srv:submission', "_submission._tcp.{$domain}", $ports->smtpHost, $ports->submissionPort, 587],
            ['srv:smtps', "_smtps._tcp.{$domain}", $ports->smtpHost, $ports->smtpsPort, 465],
            ['srv:sieve', "_sieve._tcp.{$domain}", $ports->sieveHost, $ports->sievePort, 4190],
        ] as [$id, $recordName, $target, $port, $standard]) {
            if ($port !== $standard) {
                $checks[] = self::srv($id, $recordName, $target, $port);
            }
        }

        return $checks;
    }

    /**
     * @return list<ExpectedCheck>
     */
    private function serverChecks(ServerFacts $facts): array
    {
        $host = $facts->hostname;
        $unknown = $facts->ipv4 === null ? DnsCheckMessage::publicAddressUnknown('ipv4') : null;
        $checks = [
            new ExpectedCheck('a', ExpectedCheck::SERVER, $host, 'A', 'address', $facts->ipv4, unknownReason: $unknown),
            new ExpectedCheck('ptr:ipv4', ExpectedCheck::SERVER, $facts->ipv4 === null ? 'in-addr.arpa' : self::reverseIpv4($facts->ipv4), 'PTR', 'ptr', $facts->ipv4 === null ? null : $host, unknownReason: $unknown),
        ];

        if ($facts->ipv6 !== null) {
            $checks[] = new ExpectedCheck('aaaa', ExpectedCheck::SERVER, $host, 'AAAA', 'address', RecordComparator::expandIpv6($facts->ipv6));
            $checks[] = new ExpectedCheck('ptr:ipv6', ExpectedCheck::SERVER, self::reverseIpv6($facts->ipv6), 'PTR', 'ptr', $host);
        }

        $checks[] = new ExpectedCheck(
            'tlsa:25',
            ExpectedCheck::SERVER,
            '_25._tcp.'.$facts->smtpHost,
            'TLSA',
            'tlsa',
            $facts->tlsaDigest,
            unknownReason: $facts->tlsaDigest === null ? DnsCheckMessage::certificateUnavailable($facts->smtpHost, 25, $facts->tlsaFailureCause ?? 'connectionFailed') : null,
        );

        return $checks;
    }

    private function dkimCheck(string $domain): ?ExpectedCheck
    {
        try {
            $key = $this->store->read($domain);
        } catch (SharedStoreUnavailable) {
            return new ExpectedCheck('dkim', ExpectedCheck::DOMAIN, "_domainkey.{$domain}", 'TXT', 'dkim', null, unknownReason: DnsCheckMessage::dkimKeyUnavailable());
        }

        if ($key === null || $key->selector === null || $key->publicKey === null) {
            return null;
        }

        return new ExpectedCheck(
            'dkim:'.$key->selector,
            ExpectedCheck::DOMAIN,
            "{$key->selector}._domainkey.{$domain}",
            'TXT',
            'dkim',
            (new DkimPublicKey($key->publicKey))->recordValue(),
            data: ['publicKey' => $key->publicKey],
        );
    }

    private static function srv(string $id, string $recordName, string $target, int $port, ?int $standard = null, bool $nullAllowed = false): ExpectedCheck
    {
        return new ExpectedCheck(
            $id,
            ExpectedCheck::DOMAIN,
            $recordName,
            'SRV',
            'srv',
            RecordComparator::host($target).' '.$port,
            data: ['target' => $target, 'port' => $port],
            nullServiceRecordAllowed: $nullAllowed,
            onlyWithNullServiceRecord: $nullAllowed && $port === $standard,
        );
    }

    private static function reverseIpv4(string $address): string
    {
        return implode('.', array_reverse(explode('.', $address))).'.in-addr.arpa';
    }

    private static function reverseIpv6(string $address): string
    {
        return implode('.', array_reverse(str_split(str_replace(':', '', RecordComparator::expandIpv6($address))))).'.ip6.arpa';
    }
}
