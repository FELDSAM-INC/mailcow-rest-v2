<?php

namespace App\Rules;

use App\Support\Sieve\SieveValidator;

/**
 * A Sieve script that legacy's parser accepts (feature 005 FR-049): `sieveSyntax` with the parser's
 * `message` and, when known, its `line`.
 */
class SieveScript extends CodedRule
{
    protected function check(mixed $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }

        $message = (new SieveValidator)->error($value);

        return $message === null ? null : $this->violation('sieveSyntax', SieveValidator::violationParams($message), $message);
    }
}
