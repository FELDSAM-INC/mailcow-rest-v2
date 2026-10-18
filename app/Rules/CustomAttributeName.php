<?php

namespace App\Rules;

/**
 * The name of a mailbox custom attribute (feature 003 FR-037, research R21): 1 to 255 characters
 * without whitespace (`invalidFormat` with `format: attributeName`). Legacy silently removes spaces.
 */
class CustomAttributeName extends CodedRule
{
    public const PATTERN = '/^\S{1,255}$/u';

    protected function check(mixed $value): ?array
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1
            ? null
            : $this->violation('invalidFormat', ['format' => 'attributeName'], 'Must be 1 to 255 characters without whitespace.');
    }
}
