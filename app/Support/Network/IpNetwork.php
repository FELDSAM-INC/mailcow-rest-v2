<?php

namespace App\Support\Network;

/**
 * Addresses and networks of the ban service lists (feature 011 research R19, R22): legacy
 * valid_network() and valid_hostname() ported exactly, a canonical form for comparisons (lower
 * case, compressed IPv6, decimal prefix), and the network order of the ban lists (address, IPv4
 * before IPv6, then prefix length).
 */
final class IpNetwork
{
    /** Legacy valid_network(): an address, or `address/prefix` with a decimal prefix in range. */
    public static function isNetwork(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        $parts = explode('/', $value);

        if (count($parts) !== 2 || preg_match('/^\d+$/', $parts[1]) !== 1) {
            return false;
        }

        $prefix = (int) $parts[1];

        if (filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $prefix <= 32;
        }

        return filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false && $prefix <= 128;
    }

    /** Legacy valid_hostname(). */
    public static function isHostname(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    /**
     * The canonical form: `address` or `address/prefix` with the address packed and printed again
     * and the prefix as a decimal number; other values (host names) in lower case.
     */
    public static function canonical(string $value): string
    {
        $value = trim($value);
        [$address, $prefix] = array_pad(explode('/', $value, 2), 2, null);
        $packed = filter_var($address, FILTER_VALIDATE_IP) !== false ? inet_pton((string) $address) : false;

        if ($packed === false) {
            return strtolower($value);
        }

        $canonical = (string) inet_ntop($packed);

        return $prefix === null ? $canonical : $canonical.'/'.(preg_match('/^\d+$/', $prefix) === 1 ? (string) (int) $prefix : $prefix);
    }

    /**
     * @param  list<string>  $networks
     * @return list<string>
     */
    public static function sort(array $networks): array
    {
        usort($networks, fn (string $a, string $b): int => self::sortKey($a) <=> self::sortKey($b) ?: strcmp($a, $b));

        return $networks;
    }

    /**
     * @return array{int, string, int}
     */
    public static function sortKey(string $network): array
    {
        [$address, $prefix] = array_pad(explode('/', $network, 2), 2, null);
        $packed = filter_var($address, FILTER_VALIDATE_IP) !== false ? (string) inet_pton((string) $address) : '';

        return [$packed === '' ? 2 : (strlen($packed) === 4 ? 0 : 1), $packed, $prefix === null ? (strlen($packed) === 4 ? 32 : 128) : (int) $prefix];
    }

    /** The address part of a network (legacy strtok($network, '/')). */
    public static function address(string $network): string
    {
        return explode('/', $network, 2)[0];
    }
}
