<?php

namespace Tests\Feature\Auth;

use App\Clients\Ldap\LdapDirectory;
use Database\Factories\MailcowRows;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Fakes\FakeLdapDirectory;
use Tests\Support\IdentityProvider\FakeOidcProvider;
use Tests\Support\IdentityProvider\IdentityProviderFixtures;
use Tests\Support\IdentityProvider\ProviderPasswordTestHelpers;
use Tests\Support\Redis\ChannelSpy;
use Tests\TestCase;

/**
 * T067 (FR-029, US3 AS-5): password sign-in of Keycloak mailboxes through the mailpassword flow.
 */
class KeycloakPasswordSignInTest extends TestCase
{
    use ProviderPasswordTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->externalMailbox('anna@example.org', 'keycloak');
        $this->mailcow()->keycloakConfig(['mailpassword_flow' => '1', 'access_token' => '', 'mappers' => '[]', 'templates' => '[]']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function schemes(): iterable
    {
        yield 'BLF-CRYPT' => ['BLF-CRYPT'];
        yield 'SSHA512' => ['SSHA512'];
    }

    #[DataProvider('schemes')]
    public function test_the_mailcow_password_attribute_verifies_the_password(string $scheme): void
    {
        $idp = FakeOidcProvider::install()
            ->tokenIssued('client_credentials', 'service-token', null)
            ->users([IdentityProviderFixtures::keycloakUser('anna@example.org', MailcowRows::hash($scheme, 'Kc-Passw0rd!'))]);

        $this->passwordSignIn('anna@example.org', 'Kc-Passw0rd!')->assertCreated()
            ->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.authMethod', 'providerPassword');

        $lookup = $idp->last('users');
        $this->assertStringStartsWith('https://sso.example.org/admin/realms/example/users?', $lookup['url']);
        $this->assertSame(['email' => 'anna@example.org', 'exact' => 'true'], $lookup['query']);
        $this->assertSame(['Bearer service-token'], $lookup['headers']['Authorization']);
    }

    public function test_an_expired_service_token_is_renewed_once(): void
    {
        $this->mailcow()->identityProviderRows(['access_token' => 'cached']);
        $idp = FakeOidcProvider::install()
            ->introspection(true)
            ->status('users', 401)
            ->users([IdentityProviderFixtures::keycloakUser('anna@example.org', MailcowRows::hash('BLF-CRYPT', 'Kc-Passw0rd!'))])
            ->tokenIssued('client_credentials', 'fresh-token', null);

        $this->passwordSignIn('anna@example.org', 'Kc-Passw0rd!')->assertCreated();

        $idp->assertSentCount('users', 2);
        $this->assertSame(['Bearer fresh-token'], $idp->last('users')['headers']['Authorization']);
    }

    public function test_without_the_mailpassword_flow_the_password_cannot_be_verified(): void
    {
        $this->mailcow()->identityProviderRows(['mailpassword_flow' => '0']);
        $idp = FakeOidcProvider::install();
        $spy = ChannelSpy::fail2ban();

        $this->passwordSignIn('anna@example.org', 'Kc-Passw0rd!')->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/sign-in-failed');

        $idp->assertNothingSent();
        $spy->assertNothingPublished();
        $this->assertSame('externalIdentitySource', $this->signInAudit()[0]->failure_reason);
        unset($spy);
    }

    public function test_a_wrong_password_is_reported_to_fail2ban(): void
    {
        FakeOidcProvider::install()
            ->tokenIssued('client_credentials', 'service-token', null)
            ->users([IdentityProviderFixtures::keycloakUser('anna@example.org', MailcowRows::hash('BLF-CRYPT', 'Kc-Passw0rd!'))]);
        $spy = ChannelSpy::fail2ban();

        $this->passwordSignIn('anna@example.org', 'wrong')->assertUnauthorized();

        $spy->assertPublished('mailcow UI: Invalid password for anna@example.org by 127.0.0.1');
        $this->assertSame('invalidCredentials', $this->signInAudit()[0]->failure_reason);
        unset($spy);
    }

    public function test_the_ldap_directory_is_not_used_for_keycloak(): void
    {
        $ldap = new FakeLdapDirectory;
        $this->app->instance(LdapDirectory::class, $ldap);
        FakeOidcProvider::install()->tokenIssued('client_credentials', 'service-token', null)->users([]);

        $this->passwordSignIn('anna@example.org', 'Kc-Passw0rd!')->assertUnauthorized();

        $ldap->assertNotContacted();
    }
}
