<?php

namespace App\Rules;

/**
 * A JSON boolean (`true` or `false`), unlike Laravel's `boolean` rule, which also accepts `0`, `1`
 * and their strings: `invalidType` with `expected: boolean`.
 */
class StrictBoolean extends CodedRule
{
    protected function check(mixed $value): ?array
    {
        return is_bool($value) ? null : $this->violation('invalidType', ['expected' => 'boolean']);
    }
}
