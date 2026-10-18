<?php

namespace Tests\Feature\IdentityProvider;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IdentityProvider\IdentityProviderFixtures;
use Tests\Support\IdentityProvider\IdentityProviderTestHelpers;
use Tests\TestCase;

/**
 * T033 (FR-014, US1 AS-2, AS-3): secrets are write-only, kept when omitted, and required again when
 * the address that receives them changes.
 */
class IdentityProviderSecretTest extends TestCase
{
    use IdentityProviderTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->mailboxTemplate('Staff');
    }

    /**
     * @param  array<string, mixed>  $error
     */
    private function assertSecretRequired(array $error, string $reason, ?string $field = null): void
    {
        $this->assertSame('secretRequired', $error['code']);
        $this->assertSame($field === null ? ['reason' => $reason] : ['reason' => $reason, 'field' => $field], $error['params']);
    }

    public function test_a_first_save_needs_the_secret(): void
    {
        $this->asAdmin();
        $body = IdentityProviderFixtures::ldap();
        unset($body['bindPassword']);

        $response = $this->putProvider($body)->assertUnprocessable();

        $this->assertSecretRequired((array) $response->json('errors.bindPassword.0'), 'noStoredSecret');
        $this->assertSame([], $this->providerRows());
    }

    public function test_a_type_change_needs_the_new_types_secret(): void
    {
        $this->mailcow()->ldapConfig();
        $this->asAdmin();
        $body = IdentityProviderFixtures::keycloak();
        unset($body['clientSecret']);

        $response = $this->putProvider($body)->assertUnprocessable();

        $this->assertSecretRequired((array) $response->json('errors.clientSecret.0'), 'typeChanged');
        $this->assertSame('ldap', $this->providerRows()['authsource']);
    }

    public function test_a_later_save_without_the_secret_keeps_it(): void
    {
        $this->mailcow()->keycloakConfig();
        $this->asAdmin();
        $body = IdentityProviderFixtures::keycloak(['syncInterval' => 20]);
        unset($body['clientSecret']);

        $this->putProvider($body)->assertOk()->assertJsonPath('data.clientSecretSet', true);

        $rows = $this->providerRows();
        $this->assertSame('kc-secret', $rows['client_secret']);
        $this->assertSame('20', $rows['sync_interval']);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string, string}>
     */
    public static function addressChanges(): iterable
    {
        yield 'keycloak server url' => ['keycloak', ['serverUrl' => 'https://evil.example.net'], 'clientSecret', 'serverUrl'];
        yield 'generic oidc token endpoint' => ['genericOidc', ['tokenEndpoint' => 'https://evil.example.net/token'], 'clientSecret', 'tokenEndpoint'];
        yield 'ldap hosts' => ['ldap', ['hosts' => ['evil.example.net']], 'bindPassword', 'hosts'];
    }

    /**
     * @param  array<string, mixed>  $change
     */
    #[DataProvider('addressChanges')]
    public function test_a_changed_address_needs_the_secret_again(string $type, array $change, string $secretField, string $addressField): void
    {
        match ($type) {
            'keycloak' => $this->mailcow()->keycloakConfig(),
            'genericOidc' => $this->mailcow()->genericOidcConfig(),
            default => $this->mailcow()->ldapConfig(),
        };
        $before = $this->providerRows();
        $this->asAdmin();
        $body = match ($type) {
            'keycloak' => IdentityProviderFixtures::keycloak($change),
            'genericOidc' => IdentityProviderFixtures::genericOidc($change),
            default => IdentityProviderFixtures::ldap($change),
        };
        unset($body[$secretField]);

        $response = $this->putProvider($body)->assertUnprocessable();
        $this->assertSecretRequired((array) $response->json('errors.'.$secretField.'.0'), 'addressChanged', $addressField);

        $patch = $this->patchProvider($change)->assertUnprocessable();
        $this->assertSecretRequired((array) $patch->json('errors.'.$secretField.'.0'), 'addressChanged', $addressField);

        $this->assertSame($before, $this->providerRows(), 'nothing changed');
    }

    public function test_a_trailing_slash_does_not_change_the_server_url(): void
    {
        $this->mailcow()->keycloakConfig();
        $this->asAdmin();

        $this->patchProvider(['serverUrl' => 'https://sso.example.org/'])->assertOk();

        $this->assertSame('kc-secret', $this->providerRows()['client_secret']);
    }

    public function test_with_the_secret_sent_the_address_changes_and_the_tag_changes(): void
    {
        $this->mailcow()->keycloakConfig();
        $this->asAdmin();
        $before = (string) $this->getJson('/api/v2/identity-provider')->json('data.etag');

        $response = $this->patchProvider(['serverUrl' => 'https://sso2.example.org', 'clientSecret' => 'kc-secret-2'])->assertOk();

        $this->assertNotSame($before, $response->json('data.etag'));
        $this->assertSame('kc-secret-2', $this->providerRows()['client_secret']);
        $this->assertStringNotContainsString('kc-secret-2', (string) $response->getContent());
    }

    public function test_only_the_secret_changing_changes_the_tag(): void
    {
        $this->mailcow()->ldapConfig();
        $this->asAdmin();
        $before = (string) $this->getJson('/api/v2/identity-provider')->json('data.etag');

        $after = (string) $this->patchProvider(['bindPassword' => 'another-secret'])->assertOk()->json('data.etag');

        $this->assertNotSame($before, $after);
    }
}
