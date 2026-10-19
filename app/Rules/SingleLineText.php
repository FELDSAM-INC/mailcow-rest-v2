<?php

namespace App\Rules;

/**
 * Feature 013: a text without line breaks or other control characters (`\x00`–`\x1F`, `\x7F`),
 * reported as `invalidFormat` with `format: singleLine`. Lengths are checked by `max`.
 */
class SingleLineText extends CodedRule
{
    protected function check(mixed $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }

        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            ? $this->violation('invalidFormat', ['format' => 'singleLine'])
            : null;
    }

    public static function isValid(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }
}
