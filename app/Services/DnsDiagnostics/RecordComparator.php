<?php

namespace App\Services\DnsDiagnostics;

/**
 * How DNS diagnostics compares found records with the expected value (feature 010 FR-043,
 * research R17), and the comparison form in which values are reported: host names lower-case
 * without the trailing dot, IPv6 addresses compared as addresses and shown expanded (legacy
 * expand_ipv6()), TLSA data case-insensitively, SRV as `<target> <port>`, TXT with their strings
 * joined (by the resolver).
 */
final class RecordComparator
{
    public static function host(string $host): string
    {
        return strtolower(rtrim(trim($host), '.'));
    }

    /** Legacy expand_ipv6(): eight groups of four hex digits; any other text as given. */
    public static function expandIpv6(string $address): string
    {
        $packed = @inet_pton($address);

        if ($packed === false || strlen($packed) !== 16) {
            return $address;
        }

        return implode(':', str_split(bin2hex($packed), 4));
    }

    /**
     * MX: a match when any exchange is the host name; the preference is not compared.
     *
     * @param  list<array{host: string, preference: int}>  $records
     */
    public static function mx(string $expectedHost, array $records): DnsComparison
    {
        $found = array_map(fn (array $r): string => self::host($r['host']), $records);

        return self::exact(self::host($expectedHost), $found);
    }

    /**
     * The host name's A or AAAA records: a mismatch when the host name is an alias (CNAME).
     *
     * @param  list<string>  $found
     * @param  list<string>  $cnames
     */
    public static function address(string $expected, array $found, array $cnames): DnsComparison
    {
        $ipv6 = str_contains($expected, ':');
        $normalise = fn (string $a): string => $ipv6 ? self::expandIpv6($a) : $a;
        $values = array_map($normalise, $found);

        if ($cnames !== []) {
            return new DnsComparison(DnsCheckResult::MISMATCH, $values, DnsCheckMessage::hostIsAlias(self::host($cnames[0])));
        }

        return self::exact($normalise($expected), $values);
    }

    /**
     * Address records of the host name when the expected value is unknown, in comparison form.
     *
     * @param  list<string>  $found
     * @return list<string>
     */
    public static function addresses(array $found): array
    {
        return array_map(fn (string $a): string => str_contains($a, ':') ? self::expandIpv6($a) : $a, $found);
    }

    /**
     * @param  list<string>  $found
     */
    public static function ptr(string $expectedHost, array $found): DnsComparison
    {
        return self::exact(self::host($expectedHost), array_map(self::host(...), $found));
    }

    /**
     * @param  list<string>  $found
     */
    public static function tlsa(string $expected, array $found): DnsComparison
    {
        return self::exact(strtolower($expected), array_map('strtolower', $found));
    }

    /**
     * An alias to the host name. Without an alias, the name's address records match when its IPv4
     * addresses equal the host name's and its IPv6 addresses equal the host name's or neither has
     * any (with a note).
     *
     * @param  list<string>  $cnames
     * @param  list<string>  $nameIpv4
     * @param  list<string>  $nameIpv6
     * @param  list<string>  $hostIpv4
     * @param  list<string>  $hostIpv6
     */
    public static function cname(string $expectedHost, array $cnames, array $nameIpv4, array $nameIpv6, array $hostIpv4, array $hostIpv6): DnsComparison
    {
        if ($cnames !== []) {
            return self::exact(self::host($expectedHost), array_map(self::host(...), $cnames));
        }

        $v4 = self::addressSet($nameIpv4);
        $v6 = self::addressSet($nameIpv6);
        $found = [...$v4, ...$v6];

        if ($found === []) {
            return new DnsComparison(DnsCheckResult::MISSING);
        }

        if ($v4 !== [] && $v4 === self::addressSet($hostIpv4) && $v6 === self::addressSet($hostIpv6)) {
            return new DnsComparison(DnsCheckResult::MATCH, $found, null, [DnsCheckMessage::matchedByAddressRecords($v4, $v6)]);
        }

        return new DnsComparison(DnsCheckResult::MISMATCH, $found);
    }

    /**
     * SRV: target and port; priority and weight are ignored. A null record shows as `. <port>`.
     *
     * @param  list<array{target: string, port: int, priority: int, weight: int}>  $records
     */
    public static function srv(string $expectedTarget, int $expectedPort, array $records): DnsComparison
    {
        return self::exact(self::host($expectedTarget).' '.$expectedPort, self::srvValues($records));
    }

    /**
     * @param  list<array{target: string, port: int, priority: int, weight: int}>  $records
     * @return list<string>
     */
    public static function srvValues(array $records): array
    {
        return array_map(function (array $r): string {
            $target = self::host($r['target']);

            return ($target === '' ? '.' : $target).' '.$r['port'];
        }, $records);
    }

    /**
     * DKIM: a match when a found record's `p=` tag equals the stored public key, whatever the order
     * of its tags and the whitespace.
     *
     * @param  list<string>  $records
     */
    public static function dkim(string $publicKey, array $records): DnsComparison
    {
        if ($records === []) {
            return new DnsComparison(DnsCheckResult::MISSING);
        }

        foreach ($records as $record) {
            $p = self::tags($record)['p'] ?? null;

            if ($p !== null && preg_replace('/\s+/', '', $p) === $publicKey) {
                return new DnsComparison(DnsCheckResult::MATCH, $records);
            }
        }

        return new DnsComparison(DnsCheckResult::MISMATCH, $records, DnsCheckMessage::dkimPublicKeyDiffers());
    }

    /**
     * The MTA-STS record: a match when a record's `v` and `id` tags equal `STSv1` and the policy
     * identifier, whatever the spaces around the separators. Records not starting with `v=STS`
     * are not MTA-STS records.
     *
     * @param  list<string>  $records
     */
    public static function mtaSts(string $policyId, array $records): DnsComparison
    {
        $found = array_values(array_filter($records, fn (string $r): bool => preg_match('/^\s*v\s*=\s*STS/i', $r) === 1));

        foreach ($found as $record) {
            $tags = self::tags($record);

            if (($tags['v'] ?? null) === 'STSv1' && ($tags['id'] ?? null) === $policyId) {
                return new DnsComparison(DnsCheckResult::MATCH, $found);
            }
        }

        return new DnsComparison($found === [] ? DnsCheckResult::MISSING : DnsCheckResult::MISMATCH, $found);
    }

    /**
     * DMARC and TLS reporting: never a mismatch. Records starting with the version tag (for example
     * `v=DMARC1`, compared case-insensitively) are informational.
     *
     * @param  list<string>  $records
     */
    public static function informational(string $versionTag, array $records): DnsComparison
    {
        $found = array_values(array_filter($records, fn (string $r): bool => stripos(ltrim($r), $versionTag) === 0));

        return new DnsComparison($found === [] ? DnsCheckResult::MISSING : DnsCheckResult::INFORMATIONAL, $found);
    }

    /**
     * @param  list<string>  $found
     */
    private static function exact(string $expected, array $found): DnsComparison
    {
        if ($found === []) {
            return new DnsComparison(DnsCheckResult::MISSING);
        }

        return new DnsComparison(in_array($expected, $found, true) ? DnsCheckResult::MATCH : DnsCheckResult::MISMATCH, $found);
    }

    /**
     * Tags of a `tag=value; tag=value` record, names and values trimmed.
     *
     * @return array<string, string>
     */
    private static function tags(string $record): array
    {
        $tags = [];

        foreach (explode(';', $record) as $part) {
            if (str_contains($part, '=')) {
                [$name, $value] = explode('=', $part, 2);
                $tags[strtolower(trim($name))] ??= trim($value);
            }
        }

        return $tags;
    }

    /**
     * Unique addresses in comparison form, sorted.
     *
     * @param  list<string>  $addresses
     * @return list<string>
     */
    private static function addressSet(array $addresses): array
    {
        $set = array_values(array_unique(self::addresses($addresses)));
        sort($set);

        return $set;
    }
}
