<?php

namespace App\Rules;

/**
 * An e-mail address as legacy accepts it (`filter_var(FILTER_VALIDATE_EMAIL)`), reported as
 * `invalidFormat` with `format: email`. With $allowEmpty, the empty string passes (feature 005: an
 * empty recovery address removes it).
 */
class EmailAddress extends CodedRule
{
    public function __construct(private readonly bool $allowEmpty = false) {}

    protected function check(mixed $value): ?array
    {
        if ($this->allowEmpty && $value === '') {
            return null;
        }

        return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false
            ? null
            : $this->violation('invalidFormat', ['format' => 'email']);
    }
}
