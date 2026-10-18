<?php

namespace App\Rules;

use App\Support\Domain\DomainName;

/**
 * An e-mail address of feature 009 (connection test sender and recipient, BCC destination;
 * FR-011, FR-041, FR-052): trimmed, its domain part normalised like a domain name (UTS #46 to
 * ASCII, lower-cased), then valid for FILTER_VALIDATE_EMAIL and at most 255 characters; otherwise
 * `invalidFormat` with `format: email`. The local part keeps its letter case.
 */
class RoutingEmail extends CodedRule
{
    public const MAX_LENGTH = 255;

    protected function check(mixed $value): ?array
    {
        return is_string($value) && self::normalize($value) !== null ? null : $this->violation('invalidFormat', ['format' => 'email']);
    }

    /** The normalised address, or null when the value is not a valid address. */
    public static function normalize(string $value): ?string
    {
        $value = trim($value);
        $at = strrpos($value, '@');

        if ($at === false || $at === 0) {
            return null;
        }

        $domain = DomainName::normalizeValid(substr($value, $at + 1));

        if ($domain === null) {
            return null;
        }

        $address = substr($value, 0, $at).'@'.$domain;

        return strlen($address) <= self::MAX_LENGTH && filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? $address : null;
    }
}
