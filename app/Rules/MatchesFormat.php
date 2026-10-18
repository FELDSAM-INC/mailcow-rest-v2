<?php

namespace App\Rules;

/**
 * A string matching a pattern, reported as `invalidFormat` with the contract's format name (for
 * example `totpCode`, `yubicoOtp`, `base64url`).
 */
class MatchesFormat extends CodedRule
{
    public function __construct(private readonly string $pattern, private readonly string $format) {}

    public static function base64Url(): self
    {
        return new self('/^[A-Za-z0-9_-]*$/', 'base64url');
    }

    protected function check(mixed $value): ?array
    {
        return is_string($value) && preg_match($this->pattern, $value) === 1
            ? null
            : $this->violation('invalidFormat', ['format' => $this->format]);
    }
}
