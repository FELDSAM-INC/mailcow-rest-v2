<?php

namespace App\Rules;

/**
 * A quota in bytes that mailcow can store: a whole multiple of 1 MiB (feature 002 FR-009),
 * reported as `notMultipleOf` with `factor: 1048576`.
 */
class WholeMebibytes extends CodedRule
{
    public const MIB = 1048576;

    protected function check(mixed $value): ?array
    {
        return is_int($value) && $value % self::MIB === 0
            ? null
            : $this->violation('notMultipleOf', ['factor' => self::MIB], 'Must be a whole multiple of 1 MiB (1048576 bytes).');
    }
}
