<?php

namespace App\Rules;

/**
 * An SMTP password of a relayhost or transport (feature 009 FR-013): a string of at most 255
 * characters after trimming (`tooLong`); `""` removes it. Messages never echo the value.
 */
class SmtpPassword extends CodedRule
{
    public const MAX_LENGTH = 255;

    protected function check(mixed $value): ?array
    {
        if (! is_string($value)) {
            return $this->violation('invalidType', ['expected' => 'string']);
        }

        return mb_strlen(trim($value)) > self::MAX_LENGTH ? $this->violation('tooLong', ['max' => self::MAX_LENGTH]) : null;
    }
}
