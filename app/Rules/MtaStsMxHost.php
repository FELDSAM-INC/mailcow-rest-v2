<?php

namespace App\Rules;

use App\Support\Domain\DomainName;

/**
 * An MTA-STS MX host (feature 010 FR-033, research R15): trimmed, lower-cased and converted to ASCII
 * with UTS #46 like a domain name, optionally starting with `*.`, the rest a valid domain name
 * (002's domainName rule; legacy `is_valid_domain_name($mx, ['allow_wildcard' => true])`).
 * Otherwise `invalidFormat` with `format: mxHost`.
 */
class MtaStsMxHost extends CodedRule
{
    /** Also blank strings, which Laravel would otherwise skip. */
    public bool $implicit = true;

    /** The normalised host, or null when it is not a valid MX host. */
    public static function normalize(string $host): ?string
    {
        $host = trim($host);
        $wildcard = str_starts_with($host, '*.');
        $name = DomainName::normalizeValid($wildcard ? substr($host, 2) : $host);

        return $name === null ? null : ($wildcard ? '*.'.$name : $name);
    }

    protected function check(mixed $value): ?array
    {
        return is_string($value) && self::normalize($value) !== null
            ? null
            : $this->violation('invalidFormat', ['format' => 'mxHost'], 'Not a valid MX host name: use a domain name, optionally starting with "*.".');
    }
}
