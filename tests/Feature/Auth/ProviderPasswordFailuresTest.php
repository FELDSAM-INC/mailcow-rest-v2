<?php

namespace Tests\Feature\Auth;

use App\Clients\Ldap\LdapResult;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IdentityProvider\FakeOidcProvider;
use Tests\Support\IdentityProvider\IdentityProviderFixtures;
use Tests\Support\IdentityProvider\ProviderPasswordTestHelpers;
use Tests\Support\Redis\ChannelSpy;
use Tests\TestCase;

/**
 * T068 (FR-033, US3 AS-2, AS-4, AS-6, SC-008): failures of provider password sign-ins; provider
 * outages never reach fail2ban or the sign-in throttle.
 */
class ProviderPasswordFailuresTest extends TestCase
{
    use ProviderPasswordTestHelpers;

    public function test_a_wrong_directory_password_and_an_unknown_user_are_wrong_credentials(): void
    {
        $this->externalMailbox('carl@example.org', 'ldap');
        $this->directory();
        $spy = ChannelSpy::fail2ban();

        $this->passwordSignIn('carl@example.org', 'wrong')->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/sign-in-failed');
        $this->passwordSignIn('nobody@example.org', 'wrong')->assertUnauthorized();

        $spy->assertPublished('mailcow UI: Invalid password for carl@example.org by 127.0.0.1');
        $spy->assertPublished('mailcow UI: Invalid password for nobody@example.org by 127.0.0.1');
        $this->assertSame(['invalidCredentials', 'invalidCredentials'], array_column($this->signInAudit(), 'failure_reason'));
        $this->assertCount(2, $this->sleeper()->slept, 'both count towards throttling');
        unset($spy);
    }

    /**
     * @return iterable<string, array{LdapResult}>
     */
    public static function directoryOutages(): iterable
    {
        yield 'unreachable' => [LdapResult::unreachable()];
        yield 'time-out' => [LdapResult::timedOut()];
        yield 'tls' => [LdapResult::tlsFailure()];
    }

    #[DataProvider('directoryOutages')]
    public function test_a_directory_outage_is_a_temporary_failure(LdapResult $outage): void
    {
        $this->externalMailbox('carl@example.org', 'ldap');
        $this->directory()->failConnections($outage);
        $spy = ChannelSpy::fail2ban();

        $this->passwordSignIn('carl@example.org', self::DIRECTORY_PASSWORD)->assertStatus(503)
            ->assertJsonPath('type', '/api/v2/problems/identity-provider-unavailable')
            ->assertHeader('Retry-After', '30');

        $spy->assertNothingPublished();
        $this->assertSame([], $this->sleeper()->slept);
        $this->assertSame('identityProviderUnavailable', $this->signInAudit()[0]->failure_reason);
        unset($spy);
    }

    public function test_a_refused_service_bind_is_a_temporary_failure(): void
    {
        $this->externalMailbox('carl@example.org', 'ldap');
        $this->directory()->bindResult('cn=admin,dc=example,dc=org', LdapResult::credentialsRejected());

        $this->passwordSignIn('carl@example.org', self::DIRECTORY_PASSWORD)->assertStatus(503);
    }

    public function test_keycloak_outages_are_temporary_failures(): void
    {
        $this->externalMailbox('anna@example.org', 'keycloak');
        $this->mailcow()->keycloakConfig(['mailpassword_flow' => '1']);
        $spy = ChannelSpy::fail2ban();

        FakeOidcProvider::install()->tokenRefused('client_credentials', 'unauthorized_client');
        $this->passwordSignIn('anna@example.org', 'Kc-Passw0rd!')->assertStatus(503);

        FakeOidcProvider::install()->tokenIssued('client_credentials', 'service-token', null)->status('users', 403);
        $this->passwordSignIn('anna@example.org', 'Kc-Passw0rd!')->assertStatus(503);

        $spy->assertNothingPublished();
        $this->assertSame([], $this->sleeper()->slept);
        unset($spy);
    }

    public function test_a_keycloak_user_without_a_mailcow_password_cannot_be_verified(): void
    {
        $this->externalMailbox('anna@example.org', 'keycloak');
        $this->mailcow()->keycloakConfig(['mailpassword_flow' => '1']);
        FakeOidcProvider::install()->tokenIssued('client_credentials', 'service-token', null)->users([IdentityProviderFixtures::keycloakUser('anna@example.org', null)]);
        $spy = ChannelSpy::fail2ban();

        $this->passwordSignIn('anna@example.org', 'Kc-Passw0rd!')->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/sign-in-failed');

        $spy->assertNothingPublished();
        $this->assertSame([], $this->sleeper()->slept);
        $this->assertSame('passwordNotVerifiable', $this->signInAudit()[0]->failure_reason);
        unset($spy);
    }

    public function test_a_generic_oidc_mailbox_cannot_sign_in_with_a_password(): void
    {
        $this->externalMailbox('olga@example.org', 'generic-oidc');
        $this->mailcow()->genericOidcConfig();
        $idp = FakeOidcProvider::install();
        $spy = ChannelSpy::fail2ban();

        $this->passwordSignIn('olga@example.org', 'anything')->assertUnauthorized();

        $idp->assertNothingSent();
        $spy->assertNothingPublished();
        $this->assertSame('externalIdentitySource', $this->signInAudit()[0]->failure_reason);
        unset($spy);
    }

    public function test_an_incomplete_configuration_cannot_verify(): void
    {
        $this->externalMailbox('carl@example.org', 'ldap');
        $ldap = $this->directory(['bindpass' => '']);

        $this->passwordSignIn('carl@example.org', self::DIRECTORY_PASSWORD)->assertUnauthorized();

        $ldap->assertNotContacted();
        $this->assertSame('externalIdentitySource', $this->signInAudit()[0]->failure_reason);
    }

    public function test_a_throttled_attempt_never_contacts_the_provider(): void
    {
        config(['restv2.signin_max_failures' => 2]);
        $this->externalMailbox('carl@example.org', 'ldap');
        $ldap = $this->directory();

        $this->passwordSignIn('carl@example.org', 'wrong')->assertUnauthorized();
        $this->passwordSignIn('carl@example.org', 'wrong')->assertUnauthorized();
        $calls = count($ldap->calls());

        $this->passwordSignIn('carl@example.org', self::DIRECTORY_PASSWORD)->assertStatus(429);

        $this->assertCount($calls, $ldap->calls());
    }

    public function test_ten_wrong_passwords_give_ten_fail2ban_messages(): void
    {
        config(['restv2.signin_max_failures' => 100]);
        $this->externalMailbox('carl@example.org', 'ldap');
        $this->directory();
        $spy = ChannelSpy::fail2ban();

        for ($i = 0; $i < 10; $i++) {
            $this->passwordSignIn('carl@example.org', 'wrong'.$i)->assertUnauthorized();
        }

        $this->assertCount(10, $spy->messages(10));
        $this->assertSame(0, DB::table('sasl_log')->count());
        unset($spy);
    }
}
