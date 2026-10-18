<?php

namespace App\Services\DnsDiagnostics\Spf;

use App\Clients\Dns\DnsResolver;
use App\Services\DnsDiagnostics\DnsCheckMessage;
use App\Services\DnsDiagnostics\DnsCheckResult;
use App\Services\DnsDiagnostics\DnsComparison;
use App\Services\DnsDiagnostics\LookupFailed;
use App\Services\DnsDiagnostics\LookupGuard;
use SPFLib\Check\Environment;
use SPFLib\Check\Result;
use SPFLib\Check\State;
use SPFLib\Checker;

/**
 * The SPF check of DNS diagnostics (feature 010 FR-043, FR-050, research R18): RFC 7208
 * check_host() with mlocati/spf-lib for the server's public IPv4 address and, when it has one, its
 * public IPv6 address, the name as `MAIL FROM` domain and the host name as `HELO`. Both `pass` is
 * a match. Before evaluating, the name's SPF records (TXT starting with `v=spf1`) are counted: none
 * is missing, more than one a mismatch. Lookup limits, syntax errors and resolver failures map to
 * coded reasons.
 */
class SpfEvaluator
{
    public function __construct(private readonly DnsResolver $dns) {}

    public function evaluate(string $name, ?string $ipv4, ?string $ipv6, string $helo, LookupGuard $guard): DnsComparison
    {
        try {
            $txt = $guard->run(fn (): array => $this->dns->txt($name));
        } catch (LookupFailed $e) {
            return new DnsComparison(DnsCheckResult::LOOKUP_FAILED, [], $e->reason);
        }

        $records = array_values(array_filter($txt, fn (string $r): bool => preg_match('/^v=spf1(\s|$)/i', $r) === 1));

        if ($records === []) {
            return new DnsComparison(DnsCheckResult::MISSING);
        }

        if (count($records) > 1) {
            return new DnsComparison(DnsCheckResult::MISMATCH, $records, DnsCheckMessage::spfMultipleRecords(count($records)));
        }

        if ($ipv4 === null) {
            return new DnsComparison(DnsCheckResult::EXPECTED_UNKNOWN, $records, DnsCheckMessage::publicAddressUnknown('ipv4'));
        }

        foreach (array_filter([$ipv4, $ipv6]) as $address) {
            $adapter = new SpfResolverAdapter($this->dns, $guard, [strtolower(rtrim($name, '.')) => $txt]);
            $checker = new RecordingChecker($adapter);
            $result = $checker->check(new Environment($address, $helo, 'postmaster@'.$name), Checker::FLAG_CHECK_MAILFROADDRESS);

            $reason = match ($result->getCode()) {
                Result::CODE_PASS => null,
                Result::CODE_ERROR_PERMANENT => match ($checker->limitHit) {
                    'lookups' => DnsCheckMessage::spfTooManyLookups(State::MAX_DNS_LOOKUPS),
                    'voidLookups' => DnsCheckMessage::spfTooManyVoidLookups(State::MAX_VOID_DNS_LOOKUPS),
                    default => $adapter->failure() === null ? DnsCheckMessage::spfError(Result::CODE_ERROR_PERMANENT) : null,
                },
                Result::CODE_ERROR_TEMPORARY => $adapter->failure() === null ? DnsCheckMessage::spfError(Result::CODE_ERROR_TEMPORARY) : null,
                default => DnsCheckMessage::spfNotPass($address, $result->getCode()),
            };

            if ($adapter->failure() !== null && $result->getCode() !== Result::CODE_PASS) {
                return new DnsComparison(DnsCheckResult::LOOKUP_FAILED, $records, $adapter->failure()->reason);
            }

            if ($reason !== null) {
                return new DnsComparison(DnsCheckResult::MISMATCH, $records, $reason);
            }
        }

        return new DnsComparison(DnsCheckResult::MATCH, $records);
    }

    /**
     * The guidance of the SPF check (FR-050): the addresses to authorise, the operator's suggested
     * record when the server setting gives one, and where the syntax is explained.
     *
     * @return list<DnsCheckMessage>
     */
    public static function guidance(?string $ipv4, ?string $ipv6): array
    {
        $suggested = trim((string) config('restv2.dns.suggested_spf_record'));

        return array_values(array_filter([
            DnsCheckMessage::spfAuthorizeAddresses($ipv4, $ipv6),
            $suggested === '' ? null : DnsCheckMessage::spfSuggestedRecord($suggested),
            DnsCheckMessage::spfSyntaxReference(),
        ]));
    }
}
