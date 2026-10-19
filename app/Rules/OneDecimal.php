<?php

namespace App\Rules;

/**
 * A number with at most one decimal (feature 006 FR-040, the legacy spam score slider's step):
 * `notMultipleOf` with `factor: 0.1`.
 */
class OneDecimal extends CodedRule
{
    protected function check(mixed $value): ?array
    {
        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        return abs(round($value * 10) - $value * 10) < 1e-6 ? null : $this->violation('notMultipleOf', ['factor' => 0.1], 'Use at most one decimal.');
    }
}
