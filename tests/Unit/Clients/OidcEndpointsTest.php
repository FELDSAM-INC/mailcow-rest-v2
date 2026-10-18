<?php

namespace Tests\Unit\Clients;

use App\Clients\Oidc\OidcEndpoints;
use PHPUnit\Framework\TestCase;

/**
 * T015 (research R1, FR-021): Keycloak endpoints and default scopes as legacy's Keycloak provider
 * library computes them, and the generic OIDC endpoints and scopes unchanged.
 */
class OidcEndpointsTest extends TestCase
{
    public function test_keycloak_endpoints_lie_below_the_realm(): void
    {
        $endpoints = OidcEndpoints::forKeycloak('https://sso.example.org', 'example', '26.0.7');

        $this->assertSame('https://sso.example.org/realms/example/protocol/openid-connect/auth', $endpoints->authorization);
        $this->assertSame('https://sso.example.org/realms/example/protocol/openid-connect/token', $endpoints->token);
        $this->assertSame('https://sso.example.org/realms/example/protocol/openid-connect/userinfo', $endpoints->userInfo);
    }

    public function test_keycloak_scopes_depend_on_the_version(): void
    {
        $this->assertSame('profile email', OidcEndpoints::forKeycloak('https://sso.example.org', 'example', '19.0.3')->scopes);
        $this->assertSame('profile email openid', OidcEndpoints::forKeycloak('https://sso.example.org', 'example', '20.0.0')->scopes);
        $this->assertSame('profile email openid', OidcEndpoints::forKeycloak('https://sso.example.org', 'example', '26.0.7')->scopes);
        $this->assertSame('profile email', OidcEndpoints::forKeycloak('https://sso.example.org', 'example', '9')->scopes);
    }

    public function test_generic_oidc_endpoints_and_scopes_are_used_unchanged(): void
    {
        $endpoints = OidcEndpoints::forGenericOidc('https://idp.example.net/a', 'https://idp.example.net/t', 'https://idp.example.net/u', 'openid profile email mailcow_template');

        $this->assertSame('https://idp.example.net/a', $endpoints->authorization);
        $this->assertSame('https://idp.example.net/t', $endpoints->token);
        $this->assertSame('https://idp.example.net/u', $endpoints->userInfo);
        $this->assertSame('openid profile email mailcow_template', $endpoints->scopes);
    }
}
