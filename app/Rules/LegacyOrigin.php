<?php

namespace App\Rules;

/**
 * Feature 013 (FR-069, research R21): an allowed origin of the legacy API's CORS settings, `*` or
 * an origin as legacy's valid_origin() accepts it: `http` or `https`, a host name or an IP address
 * (IPv6 in brackets), an optional port, and nothing else (no credentials, path, query or fragment;
 * the text must equal its rebuilt lower-cased form). `invalidFormat` with `format: origin`.
 */
class LegacyOrigin extends CodedRule
{
    protected function check(mixed $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }

        return self::isValid($value) ? null : $this->violation('invalidFormat', ['format' => 'origin']);
    }

    /** Port of legacy valid_origin() plus `*`. */
    public static function isValid(string $origin): bool
    {
        if ($origin === '*') {
            return true;
        }

        $parts = parse_url($origin);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }

        $host = $parts['host'];
        $bare = trim($host, '[]');

        if (filter_var($bare, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false && filter_var($bare, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return strtolower($origin) === strtolower($parts['scheme']).'://'.strtolower($host).$port;
    }
}
