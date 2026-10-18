<?php

namespace App\Rules;

/**
 * An absolute `https` or `http` URL with a host, without whitespace, at most 2,000 characters
 * (feature 012 FR-010 to FR-013: `invalidFormat` with `format: httpUrl`, `tooLong`).
 */
class HttpUrl extends CodedRule
{
    public const MAX = 2000;

    protected function check(mixed $value): ?array
    {
        if (! is_string($value)) {
            return $this->violation('invalidType', ['expected' => 'string']);
        }

        if (mb_strlen($value) > self::MAX) {
            return $this->violation('tooLong', ['max' => self::MAX]);
        }

        return self::isValid($value) ? null : $this->violation('invalidFormat', ['format' => 'httpUrl'], 'Must be an absolute https or http URL.');
    }

    public static function isValid(string $value): bool
    {
        if (preg_match('/\s/u', $value) === 1) {
            return false;
        }

        $parts = parse_url($value);

        return is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), ['https', 'http'], true)
            && ($parts['host'] ?? '') !== '';
    }
}
