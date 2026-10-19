<?php

namespace App\Rules;

/**
 * The username of an administrator or domain administrator (feature 007 FR-014, research R4),
 * after trimming and lower-casing (normalize()):
 *
 *  - `required` when empty;
 *  - `tooLong` (`max: 255`) beyond 255 characters;
 *  - `invalidFormat` (`format: accountUsername`) for characters outside `a-z`, `0-9`, `.`, `_` and
 *    `-`, or without a letter or digit (legacy `ctype_alnum(str_replace(['_', '.', '-'], '', $u))`);
 *  - `reserved` (`reserved: [api]`) for `api`, in any letter case (legacy's `API` check never
 *    matched after its lower-casing).
 *
 * Uniqueness is the services' check (`409 account-exists`), atomic with the write.
 */
class AccountUsername extends CodedRule
{
    public const MAX_LENGTH = 255;

    public const RESERVED = ['api'];

    public static function normalize(string $username): string
    {
        return mb_strtolower(trim($username));
    }

    /**
     * The violation of a username, or null; also used by the services for values that bypass the
     * Form Request (bulk targets are looked up, never validated).
     *
     * @return array{code: string, params: array<string, mixed>, message: string}|null
     */
    public function violationOf(mixed $value): ?array
    {
        return $this->check($value);
    }

    protected function check(mixed $value): ?array
    {
        if (! is_string($value)) {
            return $this->violation('invalidType', ['expected' => 'string']);
        }

        $username = self::normalize($value);

        if ($username === '') {
            return $this->violation('required');
        }

        if (mb_strlen($username) > self::MAX_LENGTH) {
            return $this->violation('tooLong', ['max' => self::MAX_LENGTH]);
        }

        if (preg_match('/^[a-z0-9._-]+$/', $username) !== 1 || preg_match('/[a-z0-9]/', $username) !== 1) {
            return $this->violation('invalidFormat', ['format' => 'accountUsername'], 'Use only the letters a-z, the digits 0-9, ".", "_" and "-", with at least one letter or digit.');
        }

        if (in_array($username, self::RESERVED, true)) {
            return $this->violation('reserved', ['reserved' => self::RESERVED]);
        }

        return null;
    }
}
