<?php

namespace App\Rules;

/**
 * The exclusion pattern of a sync job (feature 008 FR-017, research R6): it must compile as a PCRE
 * pattern between `/` delimiters, exactly as legacy checks it (`preg_match("/" . $exclude . "/",
 * null) === false` refuses), so a literal `/` must be escaped. The pattern is only compiled, never
 * matched against input. A refusal is `invalidRegex`.
 */
class SyncJobExcludePattern extends CodedRule
{
    protected function check(mixed $value): ?array
    {
        if (! is_string($value)) {
            return $this->violation('invalidType', ['expected' => 'string']);
        }

        set_error_handler(static fn (): bool => true);

        try {
            $valid = preg_match('/'.$value.'/', '') !== false;
        } finally {
            restore_error_handler();
        }

        return $valid ? null : $this->violation('invalidRegex', [], 'Not a valid regular expression (a literal "/" must be written "\/").');
    }
}
