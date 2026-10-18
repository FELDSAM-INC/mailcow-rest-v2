<?php

namespace Tests\Feature\IdentityProvider;

use Illuminate\Support\Facades\DB;
use Tests\Support\IdentityProvider\IdentityProviderFixtures;
use Tests\Support\IdentityProvider\IdentityProviderTestHelpers;
use Tests\TestCase;

/**
 * T031 (FR-005, FR-009 to FR-016, FR-052, US1 AS-1, AS-2, SC-002): whole saves are stored exactly
 * as legacy identity_provider('edit') stores them.
 */
class SaveIdentityProviderTest extends TestCase
{
    use IdentityProviderTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->mailboxTemplate('Staff');
    }

    public function test_a_first_ldap_save_creates_the_configuration_with_legacys_rows(): void
    {
        $this->asAdmin();

        $response = $this->putProvider(IdentityProviderFixtures::ldap())->assertCreated();

        $response->assertHeader('Location', '/api/v2/identity-provider')
            ->assertJsonPath('data.type', 'ldap')
            ->assertJsonPath('data.bindPasswordSet', true);
        $this->assertSame($response->headers->get('ETag'), $response->json('data.etag'));
        $this->assertStringNotContainsString('ldap-secret', (string) $response->getContent());

        $this->assertSame([
            'access_token' => '',
            'attribute_field' => 'title',
            'authsource' => 'ldap',
            'basedn' => 'ou=people,dc=example,dc=org',
            'binddn' => 'cn=admin,dc=example,dc=org',
            'bindpass' => 'ldap-secret',
            'default_template' => '',
            'filter' => '(objectClass=inetOrgPerson)',
            'host' => 'ldap1.example.org,ldap2.example.org',
            'ignore_ssl_error' => '',
            'import_users' => '0',
            'login_provisioning' => '1',
            'mappers' => '["staff"]',
            'periodic_sync' => '0',
            'port' => '389',
            'sync_interval' => '15',
            'templates' => '["Staff"]',
            'use_ssl' => '',
            'use_tls' => '',
            'username_field' => 'mail',
        ], $this->providerRows());

        $this->assertSame($response->json('data.etag'), $this->getJson('/api/v2/identity-provider')->json('data.etag'));
    }

    public function test_a_save_of_the_same_type_replaces_it_and_omitted_settings_take_their_defaults(): void
    {
        $this->mailcow()->ldapConfig(['sync_interval' => '30', 'periodic_sync' => '1', 'use_tls' => '1', 'access_token' => 'cached']);
        $this->asAdmin();

        $response = $this->putProvider([
            'type' => 'ldap',
            'hosts' => ['ldap1.example.org', 'ldap2.example.org'],
            'baseDn' => 'ou=people,dc=example,dc=org',
            'bindDn' => 'cn=admin,dc=example,dc=org',
        ])->assertOk();

        $response->assertJsonPath('data.syncInterval', 15)
            ->assertJsonPath('data.periodicSync', false)
            ->assertJsonPath('data.useStartTls', false)
            ->assertJsonPath('data.filter', null)
            ->assertJsonPath('data.attributeMapping', [])
            ->assertJsonPath('data.bindPasswordSet', true);
        $rows = $this->providerRows();
        $this->assertSame('ldap-secret', $rows['bindpass'], 'the omitted secret is kept');
        $this->assertSame('', $rows['access_token'], 'the cached service token is cleared');
        $this->assertSame('[]', $rows['mappers']);
        $this->assertSame('', $rows['filter']);
    }

    public function test_keycloak_and_generic_oidc_saves_store_legacys_rows(): void
    {
        $this->asAdmin();

        $this->putProvider(IdentityProviderFixtures::keycloak([
            'serverUrl' => 'https://sso.example.org///',
            'additionalReturnUrls' => ['https://webmail.example.org', 'https://webmail.example.org'],
            'mailpasswordFlow' => true,
            'ignoreTlsErrors' => true,
        ]))->assertCreated()->assertJsonPath('data.serverUrl', 'https://sso.example.org');

        $this->assertSame([
            'access_token' => '',
            'authsource' => 'keycloak',
            'client_id' => 'mailcow',
            'client_secret' => 'kc-secret',
            'default_template' => '',
            'ignore_ssl_error' => '1',
            'import_users' => '0',
            'login_provisioning' => '1',
            'mailpassword_flow' => '1',
            'mappers' => '["staff"]',
            'periodic_sync' => '0',
            'realm' => 'example',
            'redirect_url' => 'https://mail.example.org',
            'redirect_url_extra' => '["https:\/\/webmail.example.org"]',
            'server_url' => 'https://sso.example.org',
            'sync_interval' => '15',
            'templates' => '["Staff"]',
            'version' => '26.0.7',
        ], $this->providerRows());

        $this->putProvider(IdentityProviderFixtures::genericOidc(['createMailboxOnSignIn' => false]))->assertOk();

        $this->assertSame([
            'access_token' => '',
            'authorize_url' => 'https://idp.example.net/oauth2/authorize',
            'authsource' => 'generic-oidc',
            'client_id' => 'mailcow',
            'client_scopes' => 'openid profile email mailcow_template',
            'client_secret' => 'oidc-secret',
            'default_template' => '',
            'ignore_ssl_error' => '',
            'login_provisioning' => '',
            'mappers' => '[]',
            'redirect_url' => 'https://mail.example.org',
            'redirect_url_extra' => '[]',
            'templates' => '[]',
            'token_url' => 'https://idp.example.net/oauth2/token',
            'userinfo_url' => 'https://idp.example.net/oauth2/userinfo',
        ], $this->providerRows(), 'the Keycloak rows are removed');
    }

    public function test_ldap_normalisations_are_applied_and_shown(): void
    {
        $this->asAdmin();

        $response = $this->putProvider(IdentityProviderFixtures::ldap([
            'hosts' => [' ldap1.example.org', 'ldap2.example.org '],
            'usernameField' => 'Mail',
            'attributeField' => 'Title',
            'useSsl' => true,
            'useStartTls' => true,
            'filter' => '',
        ]))->assertCreated();

        $response->assertJsonPath('data.hosts', ['ldap1.example.org', 'ldap2.example.org'])
            ->assertJsonPath('data.usernameField', 'mail')
            ->assertJsonPath('data.attributeField', 'title')
            ->assertJsonPath('data.useSsl', true)
            ->assertJsonPath('data.useStartTls', false)
            ->assertJsonPath('data.effectivePort', 636)
            ->assertJsonPath('data.filter', null);
        $rows = $this->providerRows();
        $this->assertSame('ldap1.example.org,ldap2.example.org', $rows['host']);
        $this->assertSame(['1', ''], [$rows['use_ssl'], $rows['use_tls']]);
    }

    public function test_a_save_over_legacy_rows_of_another_type_removes_them_and_changes_no_mailbox(): void
    {
        $this->mailcow()->keycloakConfig(['access_token' => 'cached']);
        $this->mailcow()->identityProviderRows(['host' => 'leftover.example.org']);
        $this->externalMailboxes('ldap', 2);
        $mailboxes = DB::table('mailbox')->orderBy('username')->get()->toArray();
        $this->asAdmin();

        $this->putProvider(IdentityProviderFixtures::ldap())->assertOk();

        $rows = $this->providerRows();
        $this->assertArrayNotHasKey('server_url', $rows);
        $this->assertArrayNotHasKey('client_secret', $rows);
        $this->assertSame('ldap1.example.org,ldap2.example.org', $rows['host']);
        $this->assertSame('', $rows['access_token']);
        $this->assertEquals($mailboxes, DB::table('mailbox')->orderBy('username')->get()->toArray());
    }

    public function test_every_save_is_audited_with_its_changes_and_secrets_only_as_changed(): void
    {
        $this->asAdmin();

        $this->putProvider(IdentityProviderFixtures::ldap())->assertCreated();
        $this->putProvider(IdentityProviderFixtures::ldap(['syncInterval' => 30, 'bindPassword' => 'new-ldap-secret']))->assertOk();
        $this->putProvider(IdentityProviderFixtures::ldap(['syncInterval' => 30, 'bindPassword' => 'new-ldap-secret']))->assertOk();

        $creation = $this->providerAudit('identityProviderCreation');
        $this->assertCount(1, $creation);
        $this->assertSame('success', $creation[0]->outcome);
        $this->assertSame(['identityProvider', null, 'ldap'], [$creation[0]->target_type, $creation[0]->target_id, $creation[0]->target_name]);
        $this->assertSame('ldap', $creation[0]->details['type']);
        $this->assertSame(['old' => null, 'new' => ['ldap1.example.org', 'ldap2.example.org']], $creation[0]->details['changes']['hosts']);
        $this->assertSame(['changed' => true], $creation[0]->details['changes']['bindPassword']);

        $updates = $this->providerAudit('identityProviderUpdate');
        $this->assertCount(2, $updates);
        $this->assertSame(['old' => 15, 'new' => 30], $updates[0]->details['changes']['syncInterval']);
        $this->assertSame(['changed' => true], $updates[0]->details['changes']['bindPassword']);
        $this->assertSame('ldap', $updates[0]->details['previousType']);
        $this->assertSame([], $updates[1]->details['changes'], 'an unchanged save records no change');

        $all = json_encode(DB::table('restv2_audit_events')->get());
        $this->assertStringNotContainsString('ldap-secret', (string) $all);
    }

    public function test_if_none_match_star_creates_only(): void
    {
        $this->asAdmin();

        $this->putProvider(IdentityProviderFixtures::ldap(), ['If-None-Match' => '*'])->assertCreated();
        $this->putProvider(IdentityProviderFixtures::ldap(['syncInterval' => 60]), ['If-None-Match' => '*'])->assertStatus(412);

        $this->assertSame('15', $this->providerRows()['sync_interval']);
        $this->assertCount(1, $this->providerAudit('identityProviderUpdate'), 'the refusal is audited');
        $this->assertSame('versionOutdated', $this->providerAudit('identityProviderUpdate')[0]->failure_reason);
    }

    public function test_a_stale_if_match_is_refused(): void
    {
        $this->mailcow()->ldapConfig();
        $this->asAdmin();
        $etag = (string) $this->getJson('/api/v2/identity-provider')->json('data.etag');

        $this->putProvider(IdentityProviderFixtures::ldap(['syncInterval' => 20]), ['If-Match' => $etag])->assertOk();
        $this->putProvider(IdentityProviderFixtures::ldap(['syncInterval' => 25]), ['If-Match' => $etag])->assertStatus(412);

        $this->assertSame('20', $this->providerRows()['sync_interval']);
    }
}
