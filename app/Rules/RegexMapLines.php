<?php

namespace App\Rules;

use App\Support\Rspamd\RegexMapValidator;

/**
 * Feature 013 (FR-057): every line of a global regex map is valid, `invalidRegexLine` with the
 * 1-based `line` and the `reason` of the first failing one otherwise.
 */
class RegexMapLines extends CodedRule
{
    protected function check(mixed $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }

        $violation = (new RegexMapValidator)->check($value);

        return $violation === null ? null : $this->violation('invalidRegexLine', $violation);
    }
}
