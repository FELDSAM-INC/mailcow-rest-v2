<?php

namespace App\Rules;

/**
 * A delimited PCRE pattern that compiles (legacy is_valid_regex(): `@preg_match($pattern, '') !==
 * false`), reported as `invalidRegex`; the empty string passes (feature 005: no sender pattern).
 */
class DelimitedRegex extends CodedRule
{
    protected function check(mixed $value): ?array
    {
        if ($value === '') {
            return null;
        }

        return is_string($value) && @preg_match($value, '') !== false
            ? null
            : $this->violation('invalidRegex', [], 'Not a valid delimited regular expression.');
    }
}
