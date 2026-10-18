<?php

namespace App\Services\IdentityProvider;

/**
 * The outcome of a password verification by the identity provider (research R3, R8, FR-033):
 * verified (with the mapping value and the display name for the template choice and a creation),
 * a wrong password, an unknown user, a password that cannot be verified (a Keycloak user without a
 * `mailcow_password` value), or an unavailable provider.
 */
final readonly class ProviderVerification
{
    public const VERIFIED = 'verified';

    public const WRONG_PASSWORD = 'wrongPassword';

    public const UNKNOWN_USER = 'unknownUser';

    public const NOT_VERIFIABLE = 'notVerifiable';

    public const UNAVAILABLE = 'unavailable';

    private function __construct(
        public string $outcome,
        public ?string $mappingValue = null,
        public ?string $displayName = null,
    ) {}

    public static function verified(?string $mappingValue, ?string $displayName): self
    {
        return new self(self::VERIFIED, $mappingValue, $displayName);
    }

    public static function wrongPassword(): self
    {
        return new self(self::WRONG_PASSWORD);
    }

    public static function unknownUser(): self
    {
        return new self(self::UNKNOWN_USER);
    }

    public static function notVerifiable(): self
    {
        return new self(self::NOT_VERIFIABLE);
    }

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE);
    }

    public function isVerified(): bool
    {
        return $this->outcome === self::VERIFIED;
    }
}
