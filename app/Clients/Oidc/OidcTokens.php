<?php

namespace App\Clients\Oidc;

/**
 * The tokens a provider issued (research R7). Kept on the server only, never logged or returned.
 */
final readonly class OidcTokens
{
    public function __construct(
        #[\SensitiveParameter]
        public string $accessToken,
        #[\SensitiveParameter]
        public ?string $refreshToken = null,
        public ?int $expiresAt = null,
    ) {}
}
