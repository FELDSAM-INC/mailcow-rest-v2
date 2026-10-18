<?php

namespace App\Rules;

/**
 * An LDAP host: a DNS name per RFC 1123, an IPv4 address or an IPv6 address, at most 253
 * characters, without a port (feature 012 FR-012, format `hostName`).
 */
class HostName extends CodedRule
{
    protected function check(mixed $value): ?array
    {
        return is_string($value) && self::isValid($value)
            ? null
            : $this->violation('invalidFormat', ['format' => 'hostName'], 'Must be a host name or an IP address.');
    }

    public static function isValid(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return strlen($value) <= 253
            && preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/', $value) === 1;
    }
}
