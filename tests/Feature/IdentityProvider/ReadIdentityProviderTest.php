<?php

namespace Tests\Feature\IdentityProvider;

use Illuminate\Support\Facades\DB;
use Tests\Support\IdentityProvider\IdentityProviderTestHelpers;
use Tests\TestCase;

/**
 * T030 (FR-004 to FR-008, FR-059, US1 AS-8, AS-10): reading the configuration.
 */
class ReadIdentityProviderTest extends TestCase
{
    use IdentityProviderTestHelpers;

    public function test_no_configuration_is_not_configured(): void
    {
        $this->asAdmin();

        $this->getJson('/api/v2/identity-provider')
            ->assertNotFound()
            ->assertJsonPath('type', '/api/v2/problems/identity-provider-not-configured');
    }

    public function test_an_ldap_configuration_is_read_without_its_bind_password(): void
    {
        $this->mailcow()->mailboxTemplate('Staff');
        $this->mailcow()->ldapConfig(['use_ssl' => '1', 'port' => '389', 'access_token' => 'cached-token']);
        $this->asAdmin();

        $response = $this->getJson('/api/v2/identity-provider')->assertOk();

        $response->assertJsonPath('data.type', 'ldap')
            ->assertJsonPath('data.hosts', ['ldap1.example.org', 'ldap2.example.org'])
            ->assertJsonPath('data.port', 389)
            ->assertJsonPath('data.effectivePort', 636)
            ->assertJsonPath('data.useSsl', true)
            ->assertJsonPath('data.useStartTls', false)
            ->assertJsonPath('data.baseDn', 'ou=people,dc=example,dc=org')
            ->assertJsonPath('data.usernameField', 'mail')
            ->assertJsonPath('data.filter', '(objectClass=inetOrgPerson)')
            ->assertJsonPath('data.attributeField', 'title')
            ->assertJsonPath('data.bindDn', 'cn=admin,dc=example,dc=org')
            ->assertJsonPath('data.bindPasswordSet', true)
            ->assertJsonPath('data.attributeMapping', [['attributeValue' => 'staff', 'template' => 'Staff', 'templateExists' => true]])
            ->assertJsonPath('data.defaultTemplate', null)
            ->assertJsonPath('data.defaultTemplateExists', null)
            ->assertJsonPath('data.createMailboxOnSignIn', true)
            ->assertJsonPath('data.ignoreTlsErrors', false)
            ->assertJsonPath('data.complete', true)
            ->assertJsonPath('data.missingSettings', [])
            ->assertJsonPath('data.mailboxCounts', ['keycloak' => 0, 'genericOidc' => 0, 'ldap' => 0])
            ->assertJsonPath('data.synchronisation.state', 'disabled');

        $this->assertSame($response->headers->get('ETag'), $response->json('data.etag'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $response->json('data.modifiedAt'));
        $this->assertArrayNotHasKey('appReturnUrls', (array) $response->json('data'));

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('ldap-secret', $body);
        $this->assertStringNotContainsString('cached-token', $body);
        $this->assertStringNotContainsString('bindPassword"', $body);
    }

    public function test_a_keycloak_configuration_lists_the_app_return_addresses(): void
    {
        $this->mailcow()->keycloakConfig(['redirect_url_extra' => '["https:\/\/webmail.example.org"]', 'mailpassword_flow' => '1']);
        $this->asAdmin();

        $response = $this->getJson('/api/v2/identity-provider')->assertOk();

        $response->assertJsonPath('data.type', 'keycloak')
            ->assertJsonPath('data.serverUrl', 'https://sso.example.org')
            ->assertJsonPath('data.realm', 'example')
            ->assertJsonPath('data.clientId', 'mailcow')
            ->assertJsonPath('data.clientSecretSet', true)
            ->assertJsonPath('data.version', '26.0.7')
            ->assertJsonPath('data.mailpasswordFlow', true)
            ->assertJsonPath('data.legacyReturnUrl', 'https://mail.example.org')
            ->assertJsonPath('data.additionalReturnUrls', ['https://webmail.example.org'])
            ->assertJsonPath('data.appReturnUrls', [
                'https://mail.example.org/ng/sso/return',
                'https://webmail.example.org/ng/sso/return',
                'https://mail.example.net/ng/sso/return',
            ])
            ->assertJsonPath('data.attributeMapping.0.templateExists', false);
        $this->assertStringNotContainsString('kc-secret', (string) $response->getContent());
    }

    public function test_a_generic_oidc_configuration_has_no_synchronisation(): void
    {
        $this->mailcow()->genericOidcConfig(['client_scopes' => '']);
        $this->asAdmin();

        $response = $this->getJson('/api/v2/identity-provider')->assertOk();

        $response->assertJsonPath('data.type', 'genericOidc')
            ->assertJsonPath('data.scopes', 'openid profile email mailcow_template')
            ->assertJsonPath('data.tokenEndpoint', 'https://idp.example.net/oauth2/token')
            ->assertJsonPath('data.clientSecretSet', true);
        $this->assertArrayNotHasKey('synchronisation', (array) $response->json('data'));
        $this->assertStringNotContainsString('oidc-secret', (string) $response->getContent());
    }

    public function test_a_minimal_legacy_row_set_shows_the_defaults(): void
    {
        $this->mailcow()->identityProviderRows(['authsource' => 'ldap', 'host' => 'ldap.example.org', 'basedn' => 'dc=example', 'binddn' => 'cn=x', 'bindpass' => 'p', 'port' => '389']);
        $this->asAdmin();

        $this->getJson('/api/v2/identity-provider')->assertOk()
            ->assertJsonPath('data.usernameField', 'mail')
            ->assertJsonPath('data.syncInterval', 15)
            ->assertJsonPath('data.createMailboxOnSignIn', true)
            ->assertJsonPath('data.attributeMapping', [])
            ->assertJsonPath('data.filter', null)
            ->assertJsonPath('data.importUsers', false)
            ->assertJsonPath('data.complete', true);
    }

    public function test_rows_of_a_previous_type_are_not_shown_and_missing_templates_are_flagged(): void
    {
        $this->mailcow()->mailboxTemplate('Staff');
        $this->mailcow()->ldapConfig(['default_template' => 'Gone', 'mappers' => '["staff","old"]', 'templates' => '["Staff","Deleted"]']);
        $this->mailcow()->identityProviderRows(['server_url' => 'https://old.example.org', 'client_secret' => 'old-secret']);
        $this->asAdmin();

        $response = $this->getJson('/api/v2/identity-provider')->assertOk();

        $response->assertJsonPath('data.attributeMapping', [
            ['attributeValue' => 'staff', 'template' => 'Staff', 'templateExists' => true],
            ['attributeValue' => 'old', 'template' => 'Deleted', 'templateExists' => false],
        ])->assertJsonPath('data.defaultTemplate', 'Gone')->assertJsonPath('data.defaultTemplateExists', false);
        $this->assertArrayNotHasKey('serverUrl', (array) $response->json('data'));
        $this->assertStringNotContainsString('old-secret', (string) $response->getContent());
    }

    public function test_an_incomplete_legacy_configuration_is_readable(): void
    {
        $this->mailcow()->keycloakConfig(['client_secret' => '']);
        $this->asAdmin();

        $this->getJson('/api/v2/identity-provider')->assertOk()
            ->assertJsonPath('data.complete', false)
            ->assertJsonPath('data.missingSettings', ['clientSecret'])
            ->assertJsonPath('data.clientSecretSet', false);
    }

    public function test_the_mailbox_counts_cover_every_external_source(): void
    {
        $this->mailcow()->ldapConfig();
        $this->externalMailboxes('ldap', 12);
        $this->externalMailboxes('keycloak', 3);
        $this->mailcow()->mailboxWithDomain('local@example.org');
        $this->asAdmin();

        $this->getJson('/api/v2/identity-provider')->assertOk()
            ->assertJsonPath('data.mailboxCounts', ['keycloak' => 3, 'genericOidc' => 0, 'ldap' => 12]);
    }

    public function test_a_change_made_by_the_legacy_ui_is_visible_with_a_new_tag(): void
    {
        $this->mailcow()->ldapConfig();
        $this->asAdmin();

        $before = $this->getJson('/api/v2/identity-provider')->assertOk();
        DB::table('identity_provider')->where('key', 'sync_interval')->update(['value' => '30']);
        $after = $this->getJson('/api/v2/identity-provider')->assertOk();

        $after->assertJsonPath('data.syncInterval', 30);
        $this->assertNotSame($before->json('data.etag'), $after->json('data.etag'));

        // The service token cache, which legacy rewrites during sign-ins, keeps the tag.
        DB::table('identity_provider')->updateOrInsert(['key' => 'access_token'], ['value' => 'new-token']);
        $this->assertSame($after->json('data.etag'), $this->getJson('/api/v2/identity-provider')->json('data.etag'));
    }

    public function test_a_read_only_administrator_key_reads_it(): void
    {
        $this->mailcow()->admin('admin', 'Passw0rd!');
        $this->mailcow()->ldapConfig();
        $key = $this->apiKey('admin', 'readOnly');

        $this->getJson('/api/v2/identity-provider', $this->keyHeader($key))->assertOk()->assertJsonPath('data.type', 'ldap');
        $this->assertSame([], $this->allAudit(), 'reads are not audited');
    }
}
