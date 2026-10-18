<?php

namespace App\Rules;

/**
 * An SMTP username of a relayhost or transport (feature 009 FR-013): at most 255 characters after
 * trimming (`tooLong`) and no `:` (`invalidCharacter`), because the mail server splits the stored
 * `username:password` at the first colon. Messages never echo the value.
 */
class SmtpUsername extends CodedRule
{
    public const MAX_LENGTH = 255;

    protected function check(mixed $value): ?array
    {
        if (! is_string($value)) {
            return $this->violation('invalidType', ['expected' => 'string']);
        }

        $value = trim($value);

        if (mb_strlen($value) > self::MAX_LENGTH) {
            return $this->violation('tooLong', ['max' => self::MAX_LENGTH]);
        }

        if (str_contains($value, ':')) {
            return $this->violation('invalidCharacter', ['characters' => [':']]);
        }

        return null;
    }
}
