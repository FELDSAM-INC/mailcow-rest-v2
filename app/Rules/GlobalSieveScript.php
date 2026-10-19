<?php

namespace App\Rules;

use App\Support\Sieve\SieveValidator;

/**
 * Feature 013 (FR-063, research R14): a global Sieve script that legacy's parser accepts, checked
 * after its line endings were normalised; an empty or comment-only script means no global filter
 * and is accepted without parsing. `sieveSyntax` with the parser's `message` and `line` otherwise
 * (feature 005's code).
 */
class GlobalSieveScript extends CodedRule
{
    protected function check(mixed $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }

        $script = self::normalise($value);

        if (self::isEmpty($script)) {
            return null;
        }

        $error = app(SieveValidator::class)->error($script);

        return $error === null ? null : $this->violation('sieveSyntax', SieveValidator::violationParams($error));
    }

    /** CR LF and lone CR become LF, as legacy normalises them. */
    public static function normalise(string $script): string
    {
        return str_replace(["\r\n", "\r"], "\n", $script);
    }

    /** Whether the script has nothing but whitespace and `#` comments. */
    public static function isEmpty(string $script): bool
    {
        foreach (explode("\n", $script) as $line) {
            $line = trim($line);

            if ($line !== '' && ! str_starts_with($line, '#')) {
                return false;
            }
        }

        return true;
    }
}
