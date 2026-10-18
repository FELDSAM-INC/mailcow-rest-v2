<?php

namespace Tests\Feature\PublicConfig;

use Illuminate\Support\Facades\Redis;
use Tests\Support\IdentityProvider\SingleSignOnTestHelpers;
use Tests\TestCase;

/**
 * T048 (FR-020, US2 AS-9): the public configuration reports whether single sign-on is available;
 * "force single sign-on" refuses no sign-in method.
 */
class PublicConfigSingleSignOnTest extends TestCase
{
    use SingleSignOnTestHelpers;

    private function available(): bool
    {
        return (bool) $this->getJson('/api/v2/public-config')->assertOk()->json('data.externalIdentityProvider.singleSignOnAvailable');
    }

    public function test_complete_keycloak_and_generic_oidc_configurations_offer_single_sign_on(): void
    {
        $this->mailcow()->keycloakConfig();
        $this->assertTrue($this->available());

        $this->mailcow()->identityProviderRows(['client_secret' => '']);
        $this->assertFalse($this->available(), 'incomplete');

        $this->mailcow()->genericOidcConfig();
        $this->assertTrue($this->available());
    }

    public function test_ldap_and_no_configuration_offer_none(): void
    {
        $this->assertFalse($this->available());

        $this->mailcow()->ldapConfig();
        $this->assertFalse($this->available());
    }

    public function test_force_single_sign_on_refuses_no_password_sign_in(): void
    {
        $this->mailcow()->keycloakConfig();
        Redis::connection('mailcow')->set('CUSTOM_LOGIN', json_encode(['force_sso' => 1]));
        $this->mailcow()->mailboxWithDomain('user@example.org');

        $this->getJson('/api/v2/public-config')->assertOk()->assertJsonPath('data.loginPage.forceSingleSignOn', true);
        $this->postJson('/api/v2/session', ['username' => 'user@example.org', 'password' => 'Passw0rd!'], ['X-CSRF-Token' => $this->csrfToken()])
            ->assertCreated()->assertJsonPath('data.state', 'active');
    }
}
