<?php

namespace App\Services\Passwords;

use App\Support\Http\Base64Url;

/**
 * The password policy in force (feature 003 FR-063, research R4): the stored rules of Redis
 * `PASSWD_POLICY`, with the legacy defaults (minimum length 6, no class required) for rules never
 * saved. Its version tag is the SHA-256 of a canonical string over these effective values, quoted
 * and base64url-encoded, so that feature 013 computes the same tag (contract `PasswordPolicy.etag`).
 * The policy holds no secret, so a plain hash is used.
 */
final readonly class EffectivePasswordPolicy
{
    public function __construct(
        public int $minLength,
        public bool $requireLetters,
        public bool $requireDigits,
        public bool $requireSpecialCharacters,
        public bool $requireMixedCase,
    ) {}

    public function canonical(): string
    {
        return sprintf(
            'length=%d;chars=%d;special_chars=%d;lowerupper=%d;numbers=%d',
            $this->minLength,
            (int) $this->requireLetters,
            (int) $this->requireSpecialCharacters,
            (int) $this->requireMixedCase,
            (int) $this->requireDigits,
        );
    }

    public function etag(): string
    {
        return '"'.Base64Url::encode(hash('sha256', $this->canonical(), true)).'"';
    }

    /**
     * The contract's `PasswordPolicy`.
     *
     * @return array{minLength: int, requireLetters: bool, requireDigits: bool, requireSpecialCharacters: bool, requireMixedCase: bool, etag: string}
     */
    public function toArray(): array
    {
        return [
            'minLength' => $this->minLength,
            'requireLetters' => $this->requireLetters,
            'requireDigits' => $this->requireDigits,
            'requireSpecialCharacters' => $this->requireSpecialCharacters,
            'requireMixedCase' => $this->requireMixedCase,
            'etag' => $this->etag(),
        ];
    }
}
