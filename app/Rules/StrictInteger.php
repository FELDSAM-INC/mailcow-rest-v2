<?php

namespace App\Rules;

/**
 * A JSON integer, unlike Laravel's `integer` rule, which also accepts numeric strings (`"20"`):
 * `invalidType` with `expected: integer` (feature 008 research R6). Combine it with `integer` and a
 * range rule, so that the range is reported as `outOfRange`.
 */
class StrictInteger extends CodedRule
{
    protected function check(mixed $value): ?array
    {
        return is_int($value) ? null : $this->violation('invalidType', ['expected' => 'integer']);
    }
}
