<?php

namespace App\Rules;

/**
 * One of a list of allowed values, reported as `notInList` with the values in their own type (for
 * example `allowed: [1024, 2048]`, where Laravel's `in` rule would report strings). Strings may be
 * compared case-insensitively (feature 010: MTA-STS `mode` and `version`).
 */
class OneOf extends CodedRule
{
    /**
     * @param  list<int|string>  $allowed
     */
    public function __construct(private readonly array $allowed, private readonly bool $caseInsensitive = false) {}

    protected function check(mixed $value): ?array
    {
        foreach ($this->allowed as $allowed) {
            if ($value === $allowed || ($this->caseInsensitive && is_string($value) && is_string($allowed) && strcasecmp($value, $allowed) === 0)) {
                return null;
            }
        }

        return $this->violation('notInList', ['allowed' => $this->allowed]);
    }
}
