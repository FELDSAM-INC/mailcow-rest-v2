<?php

namespace App\Rules;

/**
 * Requested OIDC scopes: 1 to 50 scope names separated by single spaces, each of printable
 * characters other than space, `"` and `\` (feature 012 FR-011, format `scopeList`).
 */
class ScopeList extends CodedRule
{
    protected function check(mixed $value): ?array
    {
        return is_string($value) && preg_match('/^[\x21\x23-\x5B\x5D-\x7E]+( [\x21\x23-\x5B\x5D-\x7E]+){0,49}$/', $value) === 1
            ? null
            : $this->violation('invalidFormat', ['format' => 'scopeList'], 'Must be 1 to 50 scope names separated by single spaces.');
    }
}
