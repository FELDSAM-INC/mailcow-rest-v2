<?php

namespace App\Rules;

/**
 * An e-mail address as legacy accepts it (`filter_var(FILTER_VALIDATE_EMAIL)`), reported as
 * `invalidFormat` with `format: email`. With $allowEmpty, the empty string passes (feature 005: an
 * empty recovery address removes it). With $rejectControlCharacters, an address containing a
 * control character (`\x00`–`\x1F`, `\x7F`, possible inside a quoted local part) is refused
 * too (feature 013: legacy strips them silently before its check).
 */
class EmailAddress extends CodedRule
{
    public function __construct(
        private readonly bool $allowEmpty = false,
        private readonly bool $rejectControlCharacters = false,
    ) {}

    protected function check(mixed $value): ?array
    {
        if ($this->allowEmpty && $value === '') {
            return null;
        }

        return is_string($value) && self::isValid($value, $this->rejectControlCharacters)
            ? null
            : $this->violation('invalidFormat', ['format' => 'email']);
    }

    public static function isValid(string $value, bool $rejectControlCharacters = true): bool
    {
        if ($rejectControlCharacters && preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }
}
