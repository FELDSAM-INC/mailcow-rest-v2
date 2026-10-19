<?php

namespace App\Rules;

/**
 * An allow or block list pattern as legacy policy('add') accepts it (feature 006 FR-046), checked
 * after trimming and lower-casing: only `a-z`, digits, `@`, `_`, `.`, `-` and `*`, at least one
 * letter or digit (legacy `ctype_alnum()` of the rest; `invalidFormat` with `format: senderPattern`),
 * and at most 100 characters, the column's size (`tooLong`).
 */
class SenderPattern extends CodedRule
{
    public const MAX_LENGTH = 100;

    public static function normalize(string $pattern): string
    {
        return mb_strtolower(trim($pattern));
    }

    protected function check(mixed $value): ?array
    {
        $pattern = is_string($value) ? self::normalize($value) : '';

        if (preg_match('/^[a-z0-9@_.*-]+$/', $pattern) !== 1 || preg_match('/[a-z0-9]/', $pattern) !== 1) {
            return $this->violation('invalidFormat', ['format' => 'senderPattern'], 'Use only a-z, 0-9, @, _, ., - and *, with at least one letter or digit.');
        }

        return mb_strlen($pattern) > self::MAX_LENGTH ? $this->violation('tooLong', ['max' => self::MAX_LENGTH]) : null;
    }
}
