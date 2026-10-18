<?php

namespace App\Rules;

/**
 * The length of a string in characters, or in bytes with $bytes (feature 005): `tooShort` with
 * `min`, `tooLong` with `max` (and `unit: bytes`). Implicit, so an empty string is checked too
 * (Laravel skips its own size rules for empty strings).
 */
class CharacterLength extends CodedRule
{
    public bool $implicit = true;

    public function __construct(private readonly int $min, private readonly int $max, private readonly bool $bytes = false) {}

    protected function check(mixed $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }

        $length = $this->bytes ? strlen($value) : mb_strlen($value);

        if ($length < $this->min) {
            return $this->violation('tooShort', ['min' => $this->min]);
        }

        if ($length > $this->max) {
            return $this->violation('tooLong', $this->bytes ? ['max' => $this->max, 'unit' => 'bytes'] : ['max' => $this->max]);
        }

        return null;
    }
}
