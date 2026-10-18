<?php

namespace App\Rules;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * An RFC 3339 date-time with an explicit offset (`2026-10-18T12:00:00Z`, `...+02:00`, optional
 * fractional seconds), as the contract's `format: date-time` (`invalidFormat`,
 * `format: dateTime`). Laravel's date_format cannot accept both `Z` and offsets.
 */
class Rfc3339DateTime extends CodedRule
{
    private const PATTERN = '/^(\d{4})-(\d{2})-(\d{2})[Tt](\d{2}):(\d{2}):(\d{2})(\.\d{1,9})?([Zz]|[+-]\d{2}:\d{2})$/';

    protected function check(mixed $value): ?array
    {
        return is_string($value) && self::parse($value) !== null
            ? null
            : $this->violation('invalidFormat', ['format' => 'dateTime']);
    }

    /**
     * The time in UTC, or null when the value is not a valid RFC 3339 date-time.
     */
    public static function parse(string $value): ?CarbonImmutable
    {
        if (preg_match(self::PATTERN, $value, $m) !== 1
            || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59) {
            return null;
        }

        try {
            return CarbonImmutable::parse(strtoupper($value))->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
