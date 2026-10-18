<?php

namespace App\Clients\Oidc;

/**
 * The OAuth 2.0 / OIDC calls of single sign-on (research R1). Faked in tests with Http::fake().
 * Every call times out after `restv2.identity_provider.timeout` seconds, verifies TLS unless the
 * configuration ignores TLS errors, and never follows redirects.
 */
interface OidcClient
{
    /**
     * The provider's authorization address with the client ID, the return address, the scopes,
     * the state and a PKCE challenge (S256), and the PKCE verifier to keep.
     *
     * @return array{url: string, codeVerifier: string}
     */
    public function authorizationUrl(OidcEndpoints $endpoints, string $returnUrl, string $state): array;

    /**
     * Exchanges an authorization code with the client credentials and the PKCE verifier.
     *
     * @throws OidcFailure
     */
    public function exchangeCode(OidcEndpoints $endpoints, string $code, string $codeVerifier, string $returnUrl): OidcTokens;

    /**
     * Renews the tokens with a refresh token.
     *
     * @throws OidcFailure
     */
    public function refresh(OidcEndpoints $endpoints, string $refreshToken): OidcTokens;

    /**
     * The user details from the user info endpoint.
     *
     * @return array<string, mixed>
     *
     * @throws OidcFailure
     */
    public function userInfo(OidcEndpoints $endpoints, string $accessToken): array;
}
