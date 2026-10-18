<?php

namespace Tests\Unit\IdentityProvider;

use App\Auth\IdentityProviderType;
use App\Services\IdentityProvider\IdentityProviderConfig;
use App\Services\IdentityProvider\IdentityProviderSettingsCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * T010 (research R9, data-model "Settings, contract members and encodings"): the `identity_provider`
 * rows are read with legacy's defaults and truthiness and written byte-identically to legacy
 * identity_provider('edit'); the version tag covers every row but the cached service token, and
 * secrets only as an HMAC keyed with the app key.
 */
class IdentityProviderSettingsCodecTest extends TestCase
{
    private IdentityProviderSettingsCodec $codec;

    protected function setUp(): void
    {
        parent::setUp();

        $this->codec = new IdentityProviderSettingsCodec;
    }

    public function test_no_rows_or_no_type_is_not_configured(): void
    {
        $this->assertNull($this->codec->fromRows([]));
        $this->assertNull($this->codec->fromRows(['host' => 'ldap.example.org']));
        $this->assertNull($this->codec->fromRows(['authsource' => 'mailcow']));
    }

    public function test_missing_values_take_the_legacy_defaults(): void
    {
        $config = $this->codec->fromRows(['authsource' => 'ldap']);

        $this->assertNotNull($config);
        $this->assertSame(IdentityProviderType::Ldap, $config->type);
        $this->assertSame([], $config->get('attributeMapping'));
        $this->assertNull($config->get('defaultTemplate'));
        $this->assertTrue($config->get('createMailboxOnSignIn'));
        $this->assertFalse($config->get('ignoreTlsErrors'));
        $this->assertSame([], $config->get('hosts'));
        $this->assertSame(389, $config->get('port'));
        $this->assertFalse($config->get('useSsl'));
        $this->assertFalse($config->get('useStartTls'));
        $this->assertSame('mail', $config->get('usernameField'));
        $this->assertNull($config->get('filter'));
        $this->assertNull($config->get('attributeField'));
        $this->assertNull($config->get('baseDn'));
        $this->assertFalse($config->get('importUsers'));
        $this->assertFalse($config->get('periodicSync'));
        $this->assertSame(15, $config->get('syncInterval'));
        $this->assertNull($config->secret());

        $oidc = $this->codec->fromRows(['authsource' => 'generic-oidc']);
        $this->assertNotNull($oidc);
        $this->assertSame('openid profile email mailcow_template', $oidc->get('scopes'));
        $this->assertSame([], $oidc->get('additionalReturnUrls'));

        $keycloak = $this->codec->fromRows(['authsource' => 'keycloak']);
        $this->assertNotNull($keycloak);
        $this->assertFalse($keycloak->get('mailpasswordFlow'));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function booleans(): iterable
    {
        yield "'1'" => ['1', true];
        yield "''" => ['', false];
        yield "'0'" => ['0', false];
    }

    #[DataProvider('booleans')]
    public function test_booleans_are_read_with_php_truthiness(string $stored, bool $expected): void
    {
        $config = $this->codec->fromRows([
            'authsource' => 'ldap',
            'use_ssl' => $stored,
            'ignore_ssl_error' => $stored,
            'login_provisioning' => $stored,
            'periodic_sync' => $stored,
            'import_users' => $stored,
        ]);

        $this->assertNotNull($config);
        $this->assertSame($expected, $config->get('useSsl'));
        $this->assertSame($expected, $config->get('ignoreTlsErrors'));
        $this->assertSame($expected, $config->get('createMailboxOnSignIn'));
        $this->assertSame($expected, $config->get('periodicSync'));
        $this->assertSame($expected, $config->get('importUsers'));
    }

    public function test_json_lists_are_read_as_lists_and_as_legacy_objects(): void
    {
        $config = $this->codec->fromRows([
            'authsource' => 'keycloak',
            'redirect_url_extra' => '{"0":"https:\/\/a.example.org","2":"https:\/\/b.example.org"}',
            'mappers' => '["staff","student"]',
            'templates' => '{"0":"Staff","1":"Students"}',
        ]);

        $this->assertNotNull($config);
        $this->assertSame(['https://a.example.org', 'https://b.example.org'], $config->get('additionalReturnUrls'));
        $this->assertSame([
            ['attributeValue' => 'staff', 'template' => 'Staff'],
            ['attributeValue' => 'student', 'template' => 'Students'],
        ], $config->get('attributeMapping'));
    }

    public function test_mapping_lists_of_different_lengths_pair_only_the_shorter_length(): void
    {
        $config = $this->codec->fromRows(['authsource' => 'ldap', 'mappers' => '["a","b","c"]', 'templates' => '["A"]']);

        $this->assertNotNull($config);
        $this->assertSame([['attributeValue' => 'a', 'template' => 'A']], $config->get('attributeMapping'));

        $broken = $this->codec->fromRows(['authsource' => 'ldap', 'mappers' => 'not json', 'templates' => '["A"]']);
        $this->assertNotNull($broken);
        $this->assertSame([], $broken->get('attributeMapping'));
    }

    public function test_rows_of_another_type_are_ignored_and_empty_strings_read_as_null(): void
    {
        $config = $this->codec->fromRows([
            'authsource' => 'ldap',
            'server_url' => 'https://old.example.org',
            'client_secret' => 'old-secret',
            'host' => 'ldap.example.org',
            'filter' => '',
            'attribute_field' => '',
            'default_template' => '',
            'basedn' => '',
        ]);

        $this->assertNotNull($config);
        $this->assertArrayNotHasKey('serverUrl', $config->settings);
        $this->assertArrayNotHasKey('clientSecret', $config->settings);
        $this->assertNull($config->get('filter'));
        $this->assertNull($config->get('attributeField'));
        $this->assertNull($config->get('defaultTemplate'));
        $this->assertNull($config->get('baseDn'));
        $this->assertSame(['ldap.example.org'], $config->get('hosts'));
    }

    public function test_the_effective_port_is_636_for_ssl_on_389(): void
    {
        $ssl = $this->codec->fromRows(['authsource' => 'ldap', 'use_ssl' => '1', 'port' => '389']);
        $sslOther = $this->codec->fromRows(['authsource' => 'ldap', 'use_ssl' => '1', 'port' => '1636']);
        $plain = $this->codec->fromRows(['authsource' => 'ldap', 'port' => '389']);

        $this->assertSame(636, $ssl?->effectivePort());
        $this->assertSame(1636, $sslOther?->effectivePort());
        $this->assertSame(389, $plain?->effectivePort());
    }

    /**
     * @return iterable<string, array{array<string, string>, list<string>}>
     */
    public static function completeness(): iterable
    {
        yield 'complete keycloak' => [self::keycloakRows(), []];
        yield 'keycloak without secret (legacy row set with empty client_secret)' => [array_merge(self::keycloakRows(), ['client_secret' => '']), ['clientSecret']];
        yield 'keycloak without version and return URL' => [array_diff_key(self::keycloakRows(), ['version' => 1, 'redirect_url' => 1]), ['legacyReturnUrl', 'version']];
        yield 'complete generic oidc' => [self::oidcRows(), []];
        yield 'generic oidc without endpoints' => [array_merge(self::oidcRows(), ['authorize_url' => '', 'userinfo_url' => '']), ['authorizationEndpoint', 'userInfoEndpoint']];
        yield 'complete ldap' => [self::ldapRows(), []];
        yield 'ldap without bind password and hosts' => [array_merge(self::ldapRows(), ['bindpass' => '', 'host' => '']), ['hosts', 'bindPassword']];
        yield 'ldap without port row' => [array_diff_key(self::ldapRows(), ['port' => 1]), ['port']];
    }

    /**
     * @param  array<string, string>  $rows
     * @param  list<string>  $missing
     */
    #[DataProvider('completeness')]
    public function test_completeness_follows_legacy_init(array $rows, array $missing): void
    {
        $config = $this->codec->fromRows($rows);

        $this->assertNotNull($config);
        $this->assertSame($missing === [], $config->isComplete());
        $this->assertSame($missing, $config->missingSettings());
    }

    public function test_an_ldap_configuration_is_written_exactly_as_legacy_writes_it(): void
    {
        $config = IdentityProviderConfig::fromInput(IdentityProviderType::Ldap, [
            'hosts' => ['ldap1.example.org', ' ldap2.example.org '],
            'port' => 389,
            'useSsl' => true,
            'useStartTls' => true,
            'baseDn' => 'ou=people,dc=example,dc=org',
            'usernameField' => 'Mail',
            'filter' => '(objectClass=inetOrgPerson)',
            'attributeField' => 'Title',
            'bindDn' => 'cn=admin,dc=example,dc=org',
            'bindPassword' => 'ldap-secret',
            'periodicSync' => true,
            'syncInterval' => 30,
            'attributeMapping' => [['attributeValue' => 'staff', 'template' => 'Staff/Team']],
        ]);

        $this->assertSame([
            'authsource' => 'ldap',
            'ignore_ssl_error' => '',
            'login_provisioning' => '1',
            'mappers' => '["staff"]',
            'templates' => '["Staff\/Team"]',
            'default_template' => '',
            'host' => 'ldap1.example.org,ldap2.example.org',
            'port' => '389',
            'basedn' => 'ou=people,dc=example,dc=org',
            'username_field' => 'mail',
            'filter' => '(objectClass=inetOrgPerson)',
            'attribute_field' => 'title',
            'binddn' => 'cn=admin,dc=example,dc=org',
            'bindpass' => 'ldap-secret',
            'use_ssl' => '1',
            'use_tls' => '',
            'periodic_sync' => '1',
            'import_users' => '0',
            'sync_interval' => '30',
            'access_token' => '',
        ], $this->codec->toRows($config));
    }

    public function test_a_keycloak_configuration_is_written_exactly_as_legacy_writes_it(): void
    {
        $config = IdentityProviderConfig::fromInput(IdentityProviderType::Keycloak, [
            'serverUrl' => 'https://sso.example.org//',
            'realm' => 'example',
            'clientId' => 'mailcow',
            'clientSecret' => 'kc-secret',
            'version' => '26.0.7',
            'legacyReturnUrl' => 'https://mail.example.org',
            'additionalReturnUrls' => ['https://webmail.example.org/', 'https://webmail.example.org/', 'https://mail.example.net'],
            'mailpasswordFlow' => true,
            'defaultTemplate' => 'Default',
            'ignoreTlsErrors' => true,
            'createMailboxOnSignIn' => false,
        ]);

        $this->assertSame([
            'authsource' => 'keycloak',
            'ignore_ssl_error' => '1',
            'login_provisioning' => '',
            'mappers' => '[]',
            'templates' => '[]',
            'default_template' => 'Default',
            'server_url' => 'https://sso.example.org',
            'realm' => 'example',
            'client_id' => 'mailcow',
            'client_secret' => 'kc-secret',
            'redirect_url' => 'https://mail.example.org',
            'redirect_url_extra' => '["https:\/\/webmail.example.org\/","https:\/\/mail.example.net"]',
            'version' => '26.0.7',
            'mailpassword_flow' => '1',
            'periodic_sync' => '0',
            'import_users' => '0',
            'sync_interval' => '15',
            'access_token' => '',
        ], $this->codec->toRows($config));
    }

    public function test_a_generic_oidc_configuration_is_written_exactly_as_legacy_writes_it(): void
    {
        $config = IdentityProviderConfig::fromInput(IdentityProviderType::GenericOidc, [
            'authorizationEndpoint' => 'https://idp.example.net/authorize',
            'tokenEndpoint' => 'https://idp.example.net/token',
            'userInfoEndpoint' => 'https://idp.example.net/userinfo',
            'clientId' => 'mailcow',
            'clientSecret' => 'oidc-secret',
            'legacyReturnUrl' => 'https://mail.example.org',
            'attributeMapping' => [['attributeValue' => 'é', 'template' => 'Staff']],
        ]);

        $this->assertSame([
            'authsource' => 'generic-oidc',
            'ignore_ssl_error' => '',
            'login_provisioning' => '1',
            'mappers' => '["\u00e9"]',
            'templates' => '["Staff"]',
            'default_template' => '',
            'authorize_url' => 'https://idp.example.net/authorize',
            'token_url' => 'https://idp.example.net/token',
            'userinfo_url' => 'https://idp.example.net/userinfo',
            'client_id' => 'mailcow',
            'client_secret' => 'oidc-secret',
            'redirect_url' => 'https://mail.example.org',
            'redirect_url_extra' => '[]',
            'client_scopes' => 'openid profile email mailcow_template',
            'access_token' => '',
        ], $this->codec->toRows($config));
    }

    public function test_written_rows_read_back_to_the_same_configuration(): void
    {
        foreach ([self::keycloakRows(), self::oidcRows(), self::ldapRows()] as $rows) {
            $config = $this->codec->fromRows($rows);
            $this->assertNotNull($config);
            $this->assertEquals($config, $this->codec->fromRows($this->codec->toRows($config)));
        }
    }

    public function test_keys_per_type(): void
    {
        $this->assertContains('access_token', $this->codec->keysFor(IdentityProviderType::Ldap));
        $this->assertContains('bindpass', $this->codec->keysFor(IdentityProviderType::Ldap));
        $this->assertNotContains('client_secret', $this->codec->keysFor(IdentityProviderType::Ldap));
        $this->assertContains('client_scopes', $this->codec->keysFor(IdentityProviderType::GenericOidc));
        $this->assertContains('mailpassword_flow', $this->codec->keysFor(IdentityProviderType::Keycloak));
    }

    public function test_the_version_tag_ignores_the_cached_service_token(): void
    {
        $rows = self::keycloakRows();

        $this->assertSame(
            $this->codec->etag($rows + ['access_token' => '']),
            $this->codec->etag($rows + ['access_token' => 'eyJhbGciOi.cached']),
        );
    }

    public function test_the_version_tag_changes_with_every_other_value_including_secrets(): void
    {
        $rows = self::keycloakRows();
        $tag = $this->codec->etag($rows);

        foreach (array_keys($rows) as $key) {
            $changed = $rows;
            $changed[$key] .= 'x';
            $this->assertNotSame($tag, $this->codec->etag($changed), "a changed {$key} must change the tag");
        }

        $this->assertNotSame($tag, $this->codec->etag($rows + ['server_url_leftover' => 'x']), 'leftover rows are covered');
        $this->assertMatchesRegularExpression('/^"[A-Za-z0-9_-]+"$/', $tag);
    }

    public function test_secrets_enter_the_version_tag_only_as_an_hmac(): void
    {
        $rows = array_merge(self::ldapRows(), self::keycloakRows());
        $input = json_encode($this->codec->etagInput($rows), JSON_UNESCAPED_SLASHES);

        $this->assertIsString($input);

        foreach (['ldap-secret', 'kc-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $input);
            $this->assertStringNotContainsString(hash('sha256', $secret), $input);
            $this->assertStringContainsString(hash_hmac('sha256', $secret, (string) config('app.key')), $input);
        }
    }

    /**
     * @return array<string, string>
     */
    private static function keycloakRows(): array
    {
        return [
            'authsource' => 'keycloak',
            'server_url' => 'https://sso.example.org',
            'realm' => 'example',
            'client_id' => 'mailcow',
            'client_secret' => 'kc-secret',
            'redirect_url' => 'https://mail.example.org',
            'redirect_url_extra' => '[]',
            'version' => '26.0.7',
            'mailpassword_flow' => '0',
            'periodic_sync' => '0',
            'import_users' => '0',
            'sync_interval' => '15',
            'ignore_ssl_error' => '',
            'login_provisioning' => '1',
            'mappers' => '["staff"]',
            'templates' => '["Staff"]',
            'default_template' => '',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function oidcRows(): array
    {
        return [
            'authsource' => 'generic-oidc',
            'authorize_url' => 'https://idp.example.net/authorize',
            'token_url' => 'https://idp.example.net/token',
            'userinfo_url' => 'https://idp.example.net/userinfo',
            'client_id' => 'mailcow',
            'client_secret' => 'oidc-secret',
            'redirect_url' => 'https://mail.example.org',
            'redirect_url_extra' => '[]',
            'client_scopes' => 'openid email',
            'ignore_ssl_error' => '',
            'login_provisioning' => '1',
            'mappers' => '[]',
            'templates' => '[]',
            'default_template' => '',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function ldapRows(): array
    {
        return [
            'authsource' => 'ldap',
            'host' => 'ldap1.example.org,ldap2.example.org',
            'port' => '389',
            'basedn' => 'ou=people,dc=example,dc=org',
            'username_field' => 'mail',
            'filter' => '',
            'attribute_field' => 'title',
            'binddn' => 'cn=admin,dc=example,dc=org',
            'bindpass' => 'ldap-secret',
            'periodic_sync' => '0',
            'import_users' => '1',
            'sync_interval' => '20',
            'use_ssl' => '',
            'use_tls' => '1',
            'ignore_ssl_error' => '',
            'login_provisioning' => '1',
            'mappers' => '["staff"]',
            'templates' => '["Staff"]',
            'default_template' => 'Default',
        ];
    }
}
