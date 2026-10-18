<?php

namespace App\Clients\Keycloak;

use App\Clients\Oidc\OidcFailure;
use App\Services\IdentityProvider\IdentityProviderConfig;

/**
 * Calls of the Keycloak service account (research R3): the client credentials grant, token
 * introspection and the user lookup of the mailpassword flow. Every call uses the configured
 * time-out and TLS setting (FR-056) and never follows redirects. Faked in tests with Http::fake().
 */
interface KeycloakAdminClient
{
    /**
     * Whether Keycloak reports the cached service token as active.
     *
     * @throws OidcFailure when Keycloak cannot be reached or answers with a server error
     */
    public function introspect(IdentityProviderConfig $config, #[\SensitiveParameter] string $token, #[\SensitiveParameter] ?string $secret = null): bool;

    /**
     * A new service token (client credentials grant).
     *
     * @throws OidcFailure with the status and OAuth error of a refusal
     */
    public function serviceToken(IdentityProviderConfig $config, #[\SensitiveParameter] ?string $secret = null): string;

    /**
     * The users whose e-mail address equals the address exactly
     * (`GET /admin/realms/<realm>/users?email=…&exact=true`).
     *
     * @return list<array<string, mixed>>
     *
     * @throws OidcFailure with the status of a refusal (401: token expired, 403: no `view-users`)
     */
    public function findUsersByEmail(IdentityProviderConfig $config, #[\SensitiveParameter] string $token, string $email): array;
}
