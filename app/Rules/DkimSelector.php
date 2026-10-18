<?php

namespace App\Rules;

/**
 * A DKIM selector: letters, digits, `.`, `-` and `_` only (legacy
 * `ctype_alnum(str_replace(['-', '_', '.'], '', $selector))`), or empty for "no key" (feature 002
 * FR-020), reported as `invalidFormat` with `format: dkimSelector`.
 */
class DkimSelector extends CodedRule
{
    protected function check(mixed $value): ?array
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9._-]*$/', $value) === 1
            ? null
            : $this->violation('invalidFormat', ['format' => 'dkimSelector']);
    }
}
