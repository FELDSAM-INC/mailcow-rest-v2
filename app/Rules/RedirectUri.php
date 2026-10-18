<?php

namespace App\Rules;

/**
 * An OAuth2 redirect URI: an absolute URI with a scheme (RFC 3986), without a fragment and without
 * whitespace (feature 012 FR-044, format `redirectUri`).
 */
class RedirectUri extends CodedRule
{
    protected function check(mixed $value): ?array
    {
        return is_string($value) && self::isValid($value)
            ? null
            : $this->violation('invalidFormat', ['format' => 'redirectUri'], 'Must be an absolute URI with a scheme, without a fragment and without whitespace.');
    }

    public static function isValid(string $value): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:[^\s#]+$/u', $value) === 1;
    }
}
