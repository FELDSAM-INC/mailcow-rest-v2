<?php

namespace Tests\Feature\SingleSignOn;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IdentityProvider\FakeOidcProvider;
use Tests\Support\IdentityProvider\IdentityProviderFixtures;
use Tests\Support\IdentityProvider\SingleSignOnTestHelpers;
use Tests\Support\Redis\ChannelSpy;
use Tests\TestCase;

/**
 * T050 (FR-022 to FR-024, FR-028, US2 AS-1, AS-5, AS-6): completing a single sign-on.
 */
class CompleteSingleSignOnTest extends TestCase
{
    use SingleSignOnTestHelpers;

    public function test_a_keycloak_mailbox_signs_in_without_a_mailcow_second_factor(): void
    {
        $this->mailcow()->keycloakConfig(['mappers' => '[]', 'templates' => '[]']);
        $this->externalMailbox('anna@example.org', 'keycloak', ['force_pw_update' => '1', 'force_tfa' => '1']);
        $this->mailcow()->tfaTotp('anna@example.org', 'JBSWY3DPEHPK3PXP', active: 0);
        $idp = $this->keycloakSignIn(IdentityProviderFixtures::userInfo('  Anna@Example.org ', 'Anna Example'));
        $started = $this->startSso();
        $before = $this->sessionCookie();

        $response = $this->completeSso($started['state'])->assertCreated();

        $response->assertHeader('Location', '/api/v2/session')
            ->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.username', 'anna@example.org')
            ->assertJsonPath('data.role', 'mailboxUser')
            ->assertJsonPath('data.authMethod', 'singleSignOn')
            ->assertJsonPath('data.pendingActions', []);
        $this->assertNotSame($before, $this->sessionCookie(), 'the session identifier is renewed');

        $this->getJson('/api/v2/me')->assertOk()
            ->assertJsonPath('data.authentication.method', 'singleSignOn')
            ->assertJsonPath('data.authentication.identityProvider', 'keycloak');

        $token = $idp->last('token:authorization_code');
        $this->assertSame('provider-code', $token['form']['code']);
        $this->assertSame('https://mail.example.org/ng/sso/return', $token['form']['redirect_uri']);
        $this->assertSame('mailcow', $token['form']['client_id']);
        $this->assertSame('kc-secret', $token['form']['client_secret']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._~-]{43,128}$/', (string) $token['form']['code_verifier']);
        $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', (string) $token['form']['code_verifier'], true)), '+/', '-_'), '='), $started['query']['code_challenge']);
        $this->assertSame(['Bearer provider-access-token'], $idp->last('userinfo')['headers']['Authorization']);

        $this->assertSame(1, DB::table('sasl_log')->where('username', 'anna@example.org')->where('service', config('restv2.sasl_log_service'))->count());
        $this->assertSame(0, (int) DB::table('tfa')->where('username', 'anna@example.org')->value('active'), 'second factors are not re-activated');

        $audit = $this->signInAudit();
        $this->assertCount(1, $audit);
        $this->assertSame(['success', 'singleSignOn', 'anna@example.org'], [$audit[0]->outcome, $audit[0]->auth_method, $audit[0]->actor_username]);
        $this->assertSame('keycloak', $audit[0]->details['identityProvider']);
        $this->assertFalse($audit[0]->details['mailboxCreated']);
        $this->assertStringNotContainsString('provider-access-token', (string) $response->getContent());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidStates(): iterable
    {
        yield 'unknown' => ['unknown'];
        yield 'expired' => ['expired'];
        yield 'already used' => ['used'];
        yield 'other session' => ['other'];
    }

    #[DataProvider('invalidStates')]
    public function test_an_invalid_state_is_refused_without_contacting_the_provider(string $case): void
    {
        $this->mailcow()->keycloakConfig();
        $this->externalMailbox('anna@example.org');
        $idp = FakeOidcProvider::install();
        $started = $this->startSso();
        $state = $started['state'];

        match ($case) {
            'unknown' => $state = 'not-the-state',
            'expired' => $this->travel(601)->seconds(),
            'used' => $this->completeSso('wrong'),
            default => $this->forgetCookies(),
        };

        $this->completeSso($state)->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/sign-in-state-invalid');

        $idp->assertNothingSent();
        $this->assertContains('signInStateInvalid', array_column($this->signInAudit(), 'failure_reason'));
    }

    public function test_a_mailbox_of_another_source_or_inactive_is_refused(): void
    {
        $this->mailcow()->keycloakConfig();
        $this->mailcow()->mailboxWithDomain('local@example.org');
        $this->externalMailbox('oidc@example.org', 'generic-oidc');
        $this->externalMailbox('off@example.org', 'keycloak', active: 0);
        $this->externalMailbox('anna@example.net', 'keycloak', domainActive: 0);
        $this->mailcow()->mailbox('room@example.org', authsource: 'keycloak', kind: 'location');

        foreach (['local@example.org' => 'mailboxNotLinked', 'oidc@example.org' => 'mailboxNotLinked', 'off@example.org' => 'accountInactive', 'anna@example.net' => 'accountInactive'] as $email => $reason) {
            $this->keycloakSignIn(IdentityProviderFixtures::userInfo($email));
            $this->completeSso($this->startSso()['state'])->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/sign-in-failed');
            $audit = array_values(array_filter($this->signInAudit(), fn ($r) => $r->actor_username === $email));
            $this->assertCount(1, $audit);
            $this->assertSame($reason, $audit[0]->failure_reason);
        }
    }

    public function test_provider_failures(): void
    {
        $this->mailcow()->keycloakConfig();
        $this->externalMailbox('anna@example.org');

        FakeOidcProvider::install()->tokenIssued()->userInfo(['sub' => 'x', 'name' => 'No Mail']);
        $this->completeSso($this->startSso()['state'])->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/sign-in-failed');

        FakeOidcProvider::install()->tokenRefused('authorization_code', 'invalid_grant');
        $this->completeSso($this->startSso()['state'])->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/sign-in-failed');

        FakeOidcProvider::install()->connectionError('token:authorization_code', 7);
        $this->completeSso($this->startSso()['state'])->assertStatus(503)
            ->assertJsonPath('type', '/api/v2/problems/identity-provider-unavailable')
            ->assertHeader('Retry-After', '30');

        FakeOidcProvider::install()->tokenIssued()->status('userinfo', 502);
        $this->completeSso($this->startSso()['state'])->assertStatus(503);

        $this->assertEqualsCanonicalizing(['noEmailAddress', 'providerRefused', 'identityProviderUnavailable', 'identityProviderUnavailable'], array_column($this->signInAudit(), 'failure_reason'));
    }

    public function test_a_configuration_removed_after_the_start_is_not_available(): void
    {
        $this->mailcow()->keycloakConfig();
        $idp = FakeOidcProvider::install();
        $started = $this->startSso();
        DB::table('identity_provider')->delete();

        $this->completeSso($started['state'])->assertStatus(409)->assertJsonPath('type', '/api/v2/problems/single-sign-on-not-available');
        $idp->assertNothingSent();
        $this->assertSame('singleSignOnNotAvailable', $this->signInAudit()[0]->failure_reason);
    }

    public function test_failures_are_neither_reported_to_fail2ban_nor_throttled(): void
    {
        $this->mailcow()->keycloakConfig();
        $this->mailcow()->mailboxWithDomain('local@example.org');
        $spy = ChannelSpy::fail2ban();

        for ($i = 0; $i < 12; $i++) {
            $this->keycloakSignIn(IdentityProviderFixtures::userInfo('local@example.org'));
            $this->completeSso($this->startSso()['state'])->assertUnauthorized();
        }

        $spy->assertNothingPublished();
        $this->assertSame([], $this->sleeper()->slept);
    }
}
