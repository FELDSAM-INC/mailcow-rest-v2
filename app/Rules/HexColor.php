<?php

namespace App\Rules;

/**
 * Feature 013 (FR-029): a six-digit hexadecimal sRGB colour `#RRGGBB` in either letter case,
 * `invalidFormat` with `format: hexColor` otherwise.
 */
class HexColor extends CodedRule
{
    protected function check(mixed $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }

        return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1 ? null : $this->violation('invalidFormat', ['format' => 'hexColor']);
    }
}
