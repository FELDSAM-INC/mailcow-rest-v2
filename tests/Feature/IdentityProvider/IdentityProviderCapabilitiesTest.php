<?php

namespace Tests\Feature\IdentityProvider;

use Tests\Support\IdentityProvider\IdentityProviderTestHelpers;
use Tests\TestCase;

/**
 * T039 (FR-064): the identity provider capabilities, available before the first save.
 */
class IdentityProviderCapabilitiesTest extends TestCase
{
    use IdentityProviderTestHelpers;

    public function test_the_capabilities_without_a_configuration(): void
    {
        $this->asAdmin();

        $this->getJson('/api/v2/capabilities/identity-provider')->assertOk()->assertExactJson(['data' => [
            'configured' => false,
            'configuredType' => null,
            'appReturnUrls' => [
                'https://mail.example.org/ng/sso/return',
                'https://webmail.example.org/ng/sso/return',
                'https://mail.example.net/ng/sso/return',
            ],
            'types' => [
                ['type' => 'keycloak', 'singleSignOn' => true, 'passwordSignIn' => true, 'synchronisation' => true],
                ['type' => 'genericOidc', 'singleSignOn' => true, 'passwordSignIn' => false, 'synchronisation' => false],
                ['type' => 'ldap', 'singleSignOn' => false, 'passwordSignIn' => true, 'synchronisation' => true],
            ],
            'defaults' => [
                'createMailboxOnSignIn' => true,
                'ignoreTlsErrors' => false,
                'scopes' => 'openid profile email mailcow_template',
                'port' => 389,
                'useSsl' => false,
                'useStartTls' => false,
                'usernameField' => 'mail',
                'mailpasswordFlow' => false,
                'importUsers' => false,
                'periodicSync' => false,
                'syncInterval' => 15,
            ],
            'limits' => [
                'attributeMappingMaxEntries' => 100,
                'attributeValueMaxLength' => 255,
                'additionalReturnUrlsMaxEntries' => 100,
                'urlMaxLength' => 2000,
                'ldapHostsMaxEntries' => 10,
                'syncIntervalMinMinutes' => 1,
                'syncIntervalMaxMinutes' => 10080,
                'secretMaxLength' => 1000,
                'scopesMaxEntries' => 50,
                'distinguishedNameMaxLength' => 1000,
                'filterMaxLength' => 1000,
            ],
            'providerTimeoutSeconds' => 7,
            'singleSignOn' => ['pendingLifetimeSeconds' => 600, 'renewalIntervalSeconds' => 300],
        ]]);
    }

    public function test_the_stored_type_without_any_secret(): void
    {
        $this->mailcow()->ldapConfig();
        $this->asAdmin();

        $response = $this->getJson('/api/v2/capabilities/identity-provider')->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.configuredType', 'ldap');
        $this->assertStringNotContainsString('ldap-secret', (string) $response->getContent());
        $this->assertSame([], $this->allAudit());
    }

    public function test_read_only_keys_may_read_and_domain_administrators_may_not(): void
    {
        $this->mailcow()->admin('admin', 'Passw0rd!');
        $this->getJson('/api/v2/capabilities/identity-provider', $this->keyHeader($this->apiKey('admin', 'readOnly')))->assertOk();

        $this->mailcow()->ensureDomain('example.org');
        $this->asDomainAdmin(['example.org']);
        $this->getJson('/api/v2/capabilities/identity-provider')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
    }
}
