<?php

namespace Tests\Feature\IdentityProvider;

use App\Clients\Ldap\LdapResult;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IdentityProvider\FakeOidcProvider;
use Tests\Support\IdentityProvider\IdentityProviderFixtures;
use Tests\Support\IdentityProvider\IdentityProviderTestHelpers;
use Tests\TestCase;

/**
 * T036 (FR-019, FR-052, FR-056, US1 AS-6, AS-8): connection tests of unsaved settings.
 */
class ConnectionTestTest extends TestCase
{
    use IdentityProviderTestHelpers;

    private const KEYCLOAK_TOKEN = 'https://sso.example.org/realms/example/protocol/openid-connect/token';

    public function test_keycloak_requests_a_service_token(): void
    {
        $idp = FakeOidcProvider::install()->tokenIssued('client_credentials', 'service-token', null);
        $this->asAdmin();

        $response = $this->testConnection(IdentityProviderFixtures::keycloak())->assertOk();

        $response->assertJsonPath('data.type', 'keycloak')
            ->assertJsonPath('data.outcome', 'success')
            ->assertJsonPath('data.failureCategory', null)
            ->assertJsonPath('data.providerStatus', 200)
            ->assertJsonPath('data.testedAddress', self::KEYCLOAK_TOKEN);
        $sent = $idp->last('token:client_credentials');
        $this->assertSame(self::KEYCLOAK_TOKEN, $sent['url']);
        $this->assertSame(['grant_type' => 'client_credentials', 'client_id' => 'mailcow', 'client_secret' => 'kc-secret'], $sent['form']);
        $this->assertTrue($sent['options']['verify']);
        $this->assertSame(7, $sent['options']['timeout']);
        $this->assertSame([], $this->providerRows(), 'nothing is stored');
        $this->assertStringNotContainsString('kc-secret', (string) $response->getContent());
        $this->assertStringNotContainsString('service-token', (string) $response->getContent());
    }

    /**
     * @return iterable<string, array{callable(FakeOidcProvider): mixed, string, int|null}>
     */
    public static function keycloakFailures(): iterable
    {
        yield 'invalid_client' => [fn (FakeOidcProvider $f) => $f->tokenRefused('client_credentials', 'invalid_client', 400), 'credentialsRejected', 400];
        yield '401' => [fn (FakeOidcProvider $f) => $f->status('token:client_credentials', 401), 'credentialsRejected', 401];
        yield 'service account off' => [fn (FakeOidcProvider $f) => $f->tokenRefused('client_credentials', 'unauthorized_client', 400), 'unexpectedResponse', 400];
        yield 'time-out' => [fn (FakeOidcProvider $f) => $f->connectionError('token:client_credentials', 28), 'timedOut', null];
        yield 'certificate' => [fn (FakeOidcProvider $f) => $f->connectionError('token:client_credentials', 60), 'tlsCertificateRejected', null];
        yield 'refused connection' => [fn (FakeOidcProvider $f) => $f->connectionError('token:client_credentials', 7), 'unreachable', null];
        yield 'unknown host' => [fn (FakeOidcProvider $f) => $f->connectionError('token:client_credentials', 6), 'unreachable', null];
        yield 'redirect' => [fn (FakeOidcProvider $f) => $f->redirect('token:client_credentials'), 'unexpectedResponse', 302];
        yield 'server error' => [fn (FakeOidcProvider $f) => $f->status('token:client_credentials', 502), 'unexpectedResponse', 502];
    }

    /**
     * @param  callable(FakeOidcProvider): mixed  $script
     */
    #[DataProvider('keycloakFailures')]
    public function test_keycloak_failures_are_classified(callable $script, string $category, ?int $status): void
    {
        $script(FakeOidcProvider::install());
        $this->asAdmin();

        $this->testConnection(IdentityProviderFixtures::keycloak())->assertOk()
            ->assertJsonPath('data.outcome', 'failure')
            ->assertJsonPath('data.failureCategory', $category)
            ->assertJsonPath('data.providerStatus', $status);

        $audit = $this->providerAudit('identityProviderConnectionTest');
        $this->assertCount(1, $audit);
        $this->assertSame(['failure', 'connectionTestFailed'], [$audit[0]->outcome, $audit[0]->failure_reason]);
        $this->assertSame($category, $audit[0]->details['failureCategory']);
    }

    public function test_generic_oidc_checks_that_the_token_endpoint_accepts_the_client(): void
    {
        $idp = FakeOidcProvider::install()->tokenRefused('authorization_code', 'invalid_grant', 400);
        $this->asAdmin();

        $this->testConnection(IdentityProviderFixtures::genericOidc())->assertOk()
            ->assertJsonPath('data.outcome', 'success')
            ->assertJsonPath('data.testedAddress', 'https://idp.example.net/oauth2/token')
            ->assertJsonPath('data.providerStatus', 400);

        $sent = $idp->last('token:authorization_code');
        $this->assertSame('mailcow', $sent['form']['client_id']);
        $this->assertSame('oidc-secret', $sent['form']['client_secret']);
        $this->assertSame('https://mail.example.org', $sent['form']['redirect_uri']);
        $this->assertNotSame('', $sent['form']['code']);
    }

    public function test_generic_oidc_with_rejected_credentials(): void
    {
        FakeOidcProvider::install()->tokenRefused('authorization_code', 'invalid_client', 401);
        $this->asAdmin();

        $this->testConnection(IdentityProviderFixtures::genericOidc())->assertOk()
            ->assertJsonPath('data.outcome', 'failure')
            ->assertJsonPath('data.failureCategory', 'credentialsRejected');
    }

    public function test_ldap_binds_with_the_bind_dn(): void
    {
        $ldap = $this->ldapFake()->password('cn=admin,dc=example,dc=org', 'ldap-secret');
        $this->asAdmin();

        $this->testConnection(IdentityProviderFixtures::ldap(['useSsl' => true]))->assertOk()
            ->assertJsonPath('data.outcome', 'success')
            ->assertJsonPath('data.testedAddress', 'ldaps://ldap1.example.org:636 ldaps://ldap2.example.org:636');

        $this->assertSame(636, parse_url(explode(' ', $ldap->calls()[0]['uris'])[0], PHP_URL_PORT));
        $ldap->assertBoundAs('cn=admin,dc=example,dc=org');
    }

    /**
     * @return iterable<string, array{LdapResult|null, string, int|null}>
     */
    public static function ldapFailures(): iterable
    {
        yield 'wrong bind password (AS-6)' => [null, 'credentialsRejected', 49];
        yield 'tls failure' => [LdapResult::tlsFailure(), 'tlsCertificateRejected', null];
        yield 'time-out' => [LdapResult::timedOut(), 'timedOut', null];
        yield 'unreachable' => [LdapResult::unreachable(), 'unreachable', null];
        yield 'other code' => [LdapResult::unexpected(53), 'unexpectedResponse', 53];
    }

    #[DataProvider('ldapFailures')]
    public function test_ldap_failures_are_classified(?LdapResult $failure, string $category, ?int $status): void
    {
        $ldap = $this->ldapFake()->password('cn=admin,dc=example,dc=org', 'ldap-secret');

        if ($failure !== null) {
            $ldap->failConnections($failure);
        }

        $this->asAdmin();

        $this->testConnection(IdentityProviderFixtures::ldap(['bindPassword' => $failure === null ? 'wrong' : 'ldap-secret']))->assertOk()
            ->assertJsonPath('data.outcome', 'failure')
            ->assertJsonPath('data.failureCategory', $category)
            ->assertJsonPath('data.providerStatus', $status);
        $this->assertSame([], $this->providerRows());
    }

    public function test_an_omitted_secret_uses_the_stored_one_only_for_the_same_type_and_address(): void
    {
        $this->mailcow()->ldapConfig();
        $ldap = $this->ldapFake()->password('cn=admin,dc=example,dc=org', 'ldap-secret');
        $this->asAdmin();
        $body = IdentityProviderFixtures::ldap(['attributeMapping' => [['attributeValue' => 'x', 'template' => 'Not There']]]);
        unset($body['bindPassword']);

        $this->testConnection($body)->assertOk()->assertJsonPath('data.outcome', 'success');

        $moved = $body;
        $moved['hosts'] = ['other.example.org'];
        $this->testConnection($moved)->assertUnprocessable()
            ->assertJsonPath('errors.bindPassword.0.code', 'secretRequired')
            ->assertJsonPath('errors.bindPassword.0.params', ['reason' => 'addressChanged', 'field' => 'hosts']);

        $keycloak = IdentityProviderFixtures::keycloak();
        unset($keycloak['clientSecret']);
        $this->testConnection($keycloak)->assertUnprocessable()
            ->assertJsonPath('errors.clientSecret.0.params.reason', 'typeChanged');
        $this->assertCount(1, $ldap->calls());
    }

    public function test_ignoring_tls_errors_disables_certificate_verification(): void
    {
        $idp = FakeOidcProvider::install()->tokenIssued('client_credentials', 'service-token', null);
        $this->asAdmin();

        $this->testConnection(IdentityProviderFixtures::keycloak(['ignoreTlsErrors' => true]))->assertOk();

        $this->assertFalse($idp->last('token:client_credentials')['options']['verify']);
    }

    public function test_the_test_is_audited_without_the_secret(): void
    {
        FakeOidcProvider::install()->tokenIssued('client_credentials', 'service-token', null);
        $this->asAdmin();

        $this->testConnection(IdentityProviderFixtures::keycloak())->assertOk();

        $audit = $this->providerAudit('identityProviderConnectionTest');
        $this->assertCount(1, $audit);
        $this->assertSame('success', $audit[0]->outcome);
        $this->assertSame([
            'type' => 'keycloak',
            'testedAddress' => self::KEYCLOAK_TOKEN,
            'outcome' => 'success',
            'failureCategory' => null,
            'providerStatus' => 200,
        ], $audit[0]->details);
        $this->assertStringNotContainsString('kc-secret', (string) json_encode($audit));
    }

    public function test_a_read_only_key_may_not_test(): void
    {
        $this->mailcow()->admin('admin', 'Passw0rd!');
        $key = $this->apiKey('admin', 'readOnly');
        $idp = FakeOidcProvider::install();

        $this->postJson('/api/v2/identity-provider/connection-tests', IdentityProviderFixtures::keycloak(), $this->keyHeader($key))
            ->assertForbidden()->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');

        $idp->assertNothingSent();
        $this->assertSame('permissionDenied', $this->providerAudit('identityProviderConnectionTest')[0]->failure_reason);
    }
}
