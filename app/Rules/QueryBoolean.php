<?php

namespace App\Rules;

/**
 * A boolean query parameter: exactly `true` or `false` (feature 002 research D26), otherwise
 * `invalidType` with `expected: boolean`.
 */
class QueryBoolean extends CodedRule
{
    protected function check(mixed $value): ?array
    {
        return in_array($value, ['true', 'false'], true) ? null : $this->violation('invalidType', ['expected' => 'boolean']);
    }
}
