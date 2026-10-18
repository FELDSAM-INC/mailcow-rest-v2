<?php

namespace App\Services\DnsDiagnostics;

use App\Clients\Dns\DnsResolver;
use App\Clients\Dns\PublicAddressDetector;
use App\Services\Config\MailcowConfig;
use App\Services\Dkim\DkimName;
use App\Services\DnsDiagnostics\Spf\SpfEvaluator;
use App\Services\Mailcow\AutodiscoverConfig;
use App\Support\Clock\Clock;

/**
 * One DNS diagnostics run (feature 010 FR-039 to FR-048, research R16 to R21): the server facts
 * (public addresses, and for administrators the TLSA digest of SMTP on port 25), the expected
 * checks in legacy's order, one lookup after another within the run's limits (LookupGuard: a
 * lookup starts only while RESTV2_DNS_LOOKUP_TIMEOUT remains of RESTV2_DNS_RUN_TIMEOUT; the rest
 * report `runTimeout`), the comparisons, the summary, the notes and the zone file. Reads only: it
 * changes nothing and writes no audit record.
 */
class DnsDiagnosticsService
{
    /** @var array<string, list<string>> host name addresses looked up in this run, by type */
    private array $hostAddresses = [];

    public function __construct(
        private readonly DnsResolver $dns,
        private readonly Clock $clock,
        private readonly PublicAddressDetector $addresses,
        private readonly TlsaProbe $tlsa,
        private readonly ExpectedRecordSet $expected,
        private readonly SpfEvaluator $spf,
        private readonly ZoneFileRenderer $zone,
        private readonly MailcowConfig $config,
    ) {}

    public function run(DkimName $name, bool $serverChecks, int $httpsPort): DnsDiagnosticsReport
    {
        $this->hostAddresses = [];
        $guard = LookupGuard::start($this->clock);
        $facts = $this->facts($serverChecks);
        $checks = [];

        foreach ($this->expected->build($name, $facts, $serverChecks, $httpsPort) as $check) {
            $result = $this->perform($check, $name->name, $facts, $guard);

            if ($result !== null) {
                $checks[] = $result;
            }
        }

        $notes = array_values(array_filter([
            $facts->ipv6 === null ? DnsCheckMessage::noPublicIpv6() : null,
            $serverChecks ? null : DnsCheckMessage::serverChecksOmitted(),
            DnsCheckMessage::propagationDelay(),
        ]));
        $suggested = trim((string) config('restv2.dns.suggested_spf_record'));

        return new DnsDiagnosticsReport(
            $name,
            $facts,
            $suggested === '' ? null : $suggested,
            $this->clock->now(),
            $serverChecks,
            $checks,
            $notes,
            $this->zone->render($name->name, $checks),
        );
    }

    private function facts(bool $serverChecks): ServerFacts
    {
        $hostname = strtolower((string) config('restv2.mailcow_hostname'));
        $addresses = $this->addresses->detect();
        $smtpHost = AutodiscoverConfig::resolve($this->config)->smtpHost;
        $tlsa = $serverChecks ? $this->tlsa->digest($smtpHost, $hostname) : ['digest' => null, 'cause' => null];

        return new ServerFacts($hostname, $addresses['ipv4'], $addresses['ipv6'], $smtpHost, $tlsa['digest'], $tlsa['cause']);
    }

    private function perform(ExpectedCheck $check, string $name, ServerFacts $facts, LookupGuard $guard): ?DnsCheckResult
    {
        $expected = $check->expected;

        try {
            $comparison = match ($check->comparison) {
                'address' => $this->address($check, $facts, $guard),
                'ptr' => $expected === null ? null : RecordComparator::ptr($expected, $guard->run(fn (): array => $this->dns->ptr($check->recordName))),
                'tlsa' => $this->tlsa($check, $guard),
                'mx' => RecordComparator::mx((string) $expected, $guard->run(fn (): array => $this->dns->mx($check->recordName))),
                'cname' => $this->cname($check, $facts, $guard),
                'srv' => $guard->run(fn (): array => $this->dns->srv($check->recordName)),
                'mtaSts' => RecordComparator::mtaSts((string) $check->data['policyId'], $guard->run(fn (): array => $this->dns->txt($check->recordName))),
                'tlsReporting' => RecordComparator::informational('v=TLSRPTv1', $guard->run(fn (): array => $this->dns->txt($check->recordName))),
                'dmarc' => RecordComparator::informational('v=DMARC1', $guard->run(fn (): array => $this->dns->txt($check->recordName))),
                'spf' => $this->spf->evaluate($name, $facts->ipv4, $facts->ipv6, $facts->hostname, $guard),
                'dkim' => $check->unknownReason !== null ? null : RecordComparator::dkim((string) $check->data['publicKey'], $guard->run(fn (): array => $this->dns->txt($check->recordName))),
                default => null,
            };
        } catch (LookupFailed $e) {
            return self::result($check, DnsCheckResult::LOOKUP_FAILED, $expected, [], $e->reason);
        }

        if (is_array($comparison)) {
            return $this->serviceRecord($check, $comparison);
        }

        if ($comparison === null) {
            return self::result($check, DnsCheckResult::EXPECTED_UNKNOWN, null, [], $check->unknownReason);
        }

        return self::result($check, $comparison->status, $expected, $comparison->found, $comparison->reason, $comparison->notes);
    }

    /**
     * The host name's A or AAAA records and whether it is an alias; unknown expected values still
     * list what was found.
     */
    private function address(ExpectedCheck $check, ServerFacts $facts, LookupGuard $guard): DnsComparison
    {
        $found = $guard->run(fn (): array => $check->recordType === 'A' ? $this->dns->a($check->recordName) : $this->dns->aaaa($check->recordName));

        if ($check->expected === null) {
            return new DnsComparison(DnsCheckResult::EXPECTED_UNKNOWN, RecordComparator::addresses($found), $check->unknownReason);
        }

        $cnames = $guard->run(fn (): array => $this->dns->cname($check->recordName));

        return RecordComparator::address($check->expected, $found, $cnames);
    }

    private function tlsa(ExpectedCheck $check, LookupGuard $guard): DnsComparison
    {
        $found = $guard->run(fn (): array => $this->dns->tlsa($check->recordName));

        return $check->expected === null
            ? new DnsComparison(DnsCheckResult::EXPECTED_UNKNOWN, array_map('strtolower', $found), $check->unknownReason)
            : RecordComparator::tlsa($check->expected, $found);
    }

    /**
     * An alias to the host name, or, without one, the name's addresses compared with the host
     * name's (looked up once per run).
     */
    private function cname(ExpectedCheck $check, ServerFacts $facts, LookupGuard $guard): DnsComparison
    {
        $cnames = $guard->run(fn (): array => $this->dns->cname($check->recordName));

        if ($cnames !== []) {
            return RecordComparator::cname((string) $check->expected, $cnames, [], [], [], []);
        }

        $nameIpv4 = $guard->run(fn (): array => $this->dns->a($check->recordName));
        $nameIpv6 = $guard->run(fn (): array => $this->dns->aaaa($check->recordName));

        if ($nameIpv4 === [] && $nameIpv6 === []) {
            return new DnsComparison(DnsCheckResult::MISSING);
        }

        $this->hostAddresses['A'] ??= $guard->run(fn (): array => $this->dns->a($facts->hostname));

        // On a server without a public IPv6 address, equal IPv4 addresses are enough (spec edge case).
        $hostIpv6 = $facts->ipv6 === null ? $nameIpv6 : ($this->hostAddresses['AAAA'] ??= $guard->run(fn (): array => $this->dns->aaaa($facts->hostname)));

        return RecordComparator::cname((string) $check->expected, [], $nameIpv4, $nameIpv6, $this->hostAddresses['A'], $hostIpv6);
    }

    /**
     * An SRV check: a null record (target `.`) for POP3 or POP3S is the expected value; without one,
     * such a check on its standard port is left out.
     *
     * @param  list<array{target: string, port: int, priority: int, weight: int}>  $records
     */
    private function serviceRecord(ExpectedCheck $check, array $records): ?DnsCheckResult
    {
        $found = RecordComparator::srvValues($records);

        if ($check->nullServiceRecordAllowed) {
            foreach ($records as $record) {
                if ($record['target'] === '.') {
                    $null = '. '.$record['port'];

                    return self::result($check, DnsCheckResult::MATCH, $null, $found, null, [DnsCheckMessage::nullServiceRecord()]);
                }
            }

            if ($check->onlyWithNullServiceRecord) {
                return null;
            }
        }

        $comparison = RecordComparator::srv((string) $check->data['target'], (int) $check->data['port'], $records);

        return self::result($check, $comparison->status, $check->expected, $comparison->found);
    }

    /**
     * @param  list<string>  $found
     * @param  list<DnsCheckMessage>  $notes
     */
    private static function result(ExpectedCheck $check, string $status, ?string $expected, array $found, ?DnsCheckMessage $reason = null, array $notes = []): DnsCheckResult
    {
        return new DnsCheckResult($check->id, $check->scope, $check->recordName, $check->recordType, $status, $check->optional, $expected, $found, $check->guidance, $reason, $notes);
    }
}
