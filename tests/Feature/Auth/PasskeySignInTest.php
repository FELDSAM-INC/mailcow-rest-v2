<?php

namespace Tests\Feature\Auth;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Auth\AuthRequests;
use Tests\Support\Redis\ChannelSpy;
use Tests\Support\WebAuthn\VirtualAuthenticator;
use Tests\Support\WebAuthn\VirtualCredential;
use Tests\TestCase;

/**
 * T069 (FR-015–FR-019, SC-001, R9, R16): usernameless passkey sign-in with credentials stored in
 * mailcow's `fido2` table, as legacy registers them.
 */
class PasskeySignInTest extends TestCase
{
    use AuthRequests;

    private const HOST = 'https://mail.example.org';

    private VirtualAuthenticator $authenticator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authenticator = new VirtualAuthenticator;
    }

    /**
     * @return TestResponse<Response>
     */
    private function challenge(): TestResponse
    {
        return $this->postEmpty(self::HOST.'/api/v2/session/passkey-challenge', ['X-CSRF-Token' => $this->csrfToken()]);
    }

    /**
     * @param  array<string, mixed>  $assertion
     * @return TestResponse<Response>
     */
    private function submit(array $assertion, ?string $role = null): TestResponse
    {
        $body = ['credential' => $assertion];

        if ($role !== null) {
            $body['role'] = $role;
        }

        return $this->postJson(self::HOST.'/api/v2/session/passkey-assertion', $body, ['X-CSRF-Token' => $this->csrfToken()]);
    }

    /**
     * Requests a challenge and answers it with the credential.
     *
     * @return TestResponse<Response>
     */
    private function signInWith(VirtualCredential $credential, ?string $role = null, bool $uv = true): TestResponse
    {
        $options = $this->challenge()->assertOk()->json('data.publicKey');

        return $this->submit($this->authenticator->assert($credential, $options, self::HOST, uv: $uv), $role);
    }

    private function passkeyFor(string $username, int $active = 1, string $rpId = 'mail.example.org'): VirtualCredential
    {
        $credential = $this->authenticator->createCredential($rpId, $username);
        $this->mailcow()->fido2Credential($username, $credential, active: $active);

        return $credential;
    }

    private function failures(): int
    {
        return (int) Redis::connection('mailcow')->get('restv2:passkey:'.hash('sha256', '127.0.0.1'));
    }

    public function test_the_challenge_is_for_discoverable_credentials(): void
    {
        $this->freezeTime();

        $response = $this->challenge()->assertOk();

        $options = $response->json('data.publicKey');
        $this->assertSame([], $options['allowCredentials']);
        $this->assertSame('preferred', $options['userVerification']);
        $this->assertSame('mail.example.org', $options['rpId']);
        $this->assertSame(300000, $options['timeout']);
        $this->assertArrayNotHasKey('extensions', $options);
        $response->assertJsonPath('data.expiresAt', CarbonImmutable::now('UTC')->addSeconds(300)->format('Y-m-d\TH:i:s\Z'));

        // A new request replaces the challenge.
        $this->assertNotSame($options['challenge'], $this->challenge()->json('data.publicKey.challenge'));
    }

    public function test_a_replaced_challenge_no_longer_verifies(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $credential = $this->passkeyFor('alice@example.org');
        $first = $this->challenge()->json('data.publicKey');
        $this->challenge()->assertOk();

        $this->submit($this->authenticator->assert($credential, $first, self::HOST))
            ->assertUnauthorized()
            ->assertJsonPath('type', '/api/v2/problems/sign-in-failed');
    }

    public function test_a_legacy_passkey_signs_a_mailbox_user_in(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $this->mailcow()->tfaTotp('alice@example.org', 'JBSWY3DPEHPK3PXP', active: 0);
        $credential = $this->passkeyFor('alice@example.org');
        $this->csrfToken();
        $before = $this->sessionCookie();
        $channel = ChannelSpy::fail2ban();

        $response = $this->signInWith($credential)->assertCreated();

        $response->assertHeader('Location', '/api/v2/session')
            ->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.username', 'alice@example.org')
            ->assertJsonPath('data.role', 'mailboxUser')
            ->assertJsonPath('data.authMethod', 'passkey');
        $this->assertNotSame($before, $this->sessionCookie());
        $this->assertFalse((bool) Redis::connection('mailcow')->exists('restv2:session:'.$before));

        $sasl = DB::table('sasl_log')->get();
        $this->assertCount(1, $sasl);
        $this->assertSame('alice@example.org', $sasl[0]->username);
        $this->assertSame('MAILCOWUI', $sasl[0]->service);
        // No second-factor change, nothing published.
        $this->assertSame(0, (int) DB::table('tfa')->where('username', 'alice@example.org')->value('active'));
        $channel->assertNothingPublished();

        $audit = $this->onlyAuditRecord('signIn');
        $this->assertSame('success', $audit->outcome);
        $this->assertSame('passkey', $audit->auth_method);
        $this->assertSame('alice@example.org', $audit->actor_username);
        $this->assertSame('mailboxUser', $audit->actor_role);
        $this->assertFalse($audit->details['restricted']);
    }

    public function test_admins_and_domain_admins_resolve_to_their_roles(): void
    {
        $this->mailcow()->ensureDomain('example.org');
        $this->mailcow()->admin('admin', 'x');
        $this->mailcow()->domainAdmin('reseller', ['example.org']);
        $admin = $this->passkeyFor('admin');
        $reseller = $this->passkeyFor('reseller');

        $this->signInWith($admin)->assertCreated()->assertJsonPath('data.role', 'admin');
        $this->signInWith($reseller)->assertCreated()->assertJsonPath('data.role', 'domainAdmin');
        $this->assertSame(0, DB::table('sasl_log')->count());
    }

    public function test_an_inactive_admin_cannot_sign_in(): void
    {
        $this->mailcow()->admin('admin', 'x', active: 0);
        $credential = $this->passkeyFor('admin');

        $this->signInWith($credential)->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/sign-in-failed');

        $audit = $this->onlyAuditRecord('signIn');
        $this->assertSame('accountInactive', $audit->failure_reason);
        $this->assertSame('admin', $audit->actor_username);
        $this->assertSame('passkey', $audit->details['step']);
        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'anonymous');
    }

    public function test_inactive_mailboxes_domains_and_resources_cannot_sign_in(): void
    {
        $this->mailcow()->ensureDomain('example.org');
        $this->mailcow()->ensureDomain('inactive.example', 0);
        $this->mailcow()->mailbox('inactive@example.org', active: 0);
        $this->mailcow()->mailbox('user@inactive.example');
        $this->mailcow()->mailbox('room@example.org', kind: 'location');

        foreach (['inactive@example.org', 'user@inactive.example', 'room@example.org'] as $username) {
            $this->signInWith($this->passkeyFor($username))->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/sign-in-failed');
        }

        $this->assertSame(['accountInactive'], array_values(array_unique(array_column($this->auditRecords('signIn'), 'failure_reason'))));
    }

    public function test_an_inactive_passkey_cannot_sign_in(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $credential = $this->passkeyFor('alice@example.org', 0);

        $this->signInWith($credential)->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/sign-in-failed');

        $this->assertSame('passkeyInactive', $this->onlyAuditRecord('signIn')->failure_reason);
    }

    public function test_an_unknown_passkey_cannot_sign_in(): void
    {
        $unknown = $this->authenticator->createCredential('mail.example.org', 'alice@example.org');

        $this->signInWith($unknown)->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/sign-in-failed');

        $audit = $this->onlyAuditRecord('signIn');
        $this->assertSame('unknownPasskey', $audit->failure_reason);
        $this->assertNull($audit->actor_username);
        $this->assertSame(1, $this->failures());
    }

    public function test_a_named_role_of_another_type_fails(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $credential = $this->passkeyFor('alice@example.org');

        $this->signInWith($credential, 'domainAdmin')->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/sign-in-failed');

        $audit = $this->onlyAuditRecord('signIn');
        $this->assertSame('roleMismatch', $audit->failure_reason);
        $this->assertSame('domainAdmin', $audit->details['requestedRole']);
    }

    public function test_a_username_in_admin_and_mailbox_signs_the_named_mailbox_in(): void
    {
        $this->mailcow()->ensureDomain('example.org');
        $this->mailcow()->admin('both@example.org', 'x');
        $this->mailcow()->mailbox('both@example.org');
        $credential = $this->passkeyFor('both@example.org');

        $this->signInWith($credential, 'mailboxUser')->assertCreated()->assertJsonPath('data.role', 'mailboxUser');
        $this->signInWith($credential)->assertCreated()->assertJsonPath('data.role', 'admin');
    }

    public function test_a_mailbox_of_an_external_identity_source_signs_in_with_a_passkey(): void
    {
        $this->mailcow()->ensureDomain('example.org');
        $this->mailcow()->mailbox('ldap@example.org', authsource: 'ldap');
        $credential = $this->passkeyFor('ldap@example.org');

        $this->signInWith($credential)->assertCreated()->assertJsonPath('data.state', 'active');
    }

    public function test_user_verification_is_required_by_default_and_follows_the_operator_setting(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $credential = $this->passkeyFor('alice@example.org');

        // Legacy passes 'preferred' to lbuchs, which then requires the UV flag (R9).
        $this->signInWith($credential, uv: false)->assertUnauthorized();
        $this->assertSame('passkeyVerificationFailed', $this->onlyAuditRecord('signIn')->failure_reason);

        config(['restv2.vars_overrides.FIDO2_UV_FLAG_LOGIN' => 'false']);
        $this->signInWith($credential, uv: false)->assertCreated();
    }

    public function test_a_forced_password_change_restricts_the_session(): void
    {
        $this->mailcow()->mailboxWithDomain('bob@example.org', ['force_pw_update' => '1']);
        $credential = $this->passkeyFor('bob@example.org');

        $this->signInWith($credential)->assertCreated()
            ->assertJsonPath('data.state', 'restricted')
            ->assertJsonPath('data.pendingActions', ['passwordChange']);
    }

    public function test_the_challenge_is_single_use(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $credential = $this->passkeyFor('alice@example.org');
        $options = $this->challenge()->json('data.publicKey');

        $this->submit($this->authenticator->assert($credential, $options, self::HOST))->assertCreated();

        $this->submit($this->authenticator->assert($credential, $options, self::HOST))
            ->assertUnauthorized()
            ->assertJsonPath('type', '/api/v2/problems/sign-in-expired');
    }

    public function test_the_challenge_expires(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $credential = $this->passkeyFor('alice@example.org');
        $options = $this->challenge()->json('data.publicKey');

        $this->travelPastChallengeTtl();

        $this->submit($this->authenticator->assert($credential, $options, self::HOST))
            ->assertUnauthorized()
            ->assertJsonPath('type', '/api/v2/problems/sign-in-expired');
        $this->assertSame('challengeExpired', $this->onlyAuditRecord('signIn')->failure_reason);
        $this->assertSame(0, $this->failures());
    }

    public function test_failures_are_throttled_per_address(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->signInWith($this->authenticator->createCredential('mail.example.org', 'x'))->assertUnauthorized();
        }

        $this->assertSame([0.0, 0.5, 1.0, 1.5, 2.0, 2.5, 3.0, 3.5, 4.0, 4.5], $this->sleeper()->slept);

        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $response = $this->signInWith($this->passkeyFor('alice@example.org'))->assertStatus(429);
        $response->assertJsonPath('type', '/api/v2/problems/too-many-requests');
        $response->assertHeader('Retry-After', (string) $response->json('retryAfter'));
        $this->assertSame('throttled', $this->auditRecords('signIn')[10]->failure_reason);

        // Another address is unaffected.
        $this->forgetCookies();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99']);
        $this->signInWith($this->passkeyFor('alice@example.org'))->assertCreated();
    }

    public function test_a_successful_sign_in_resets_the_count(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $credential = $this->passkeyFor('alice@example.org');
        $this->signInWith($this->authenticator->createCredential('mail.example.org', 'x'))->assertUnauthorized();
        $this->assertSame(1, $this->failures());

        $this->signInWith($credential)->assertCreated();

        $this->assertSame(0, $this->failures());
    }

    public function test_a_tampered_signature_fails(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $credential = $this->passkeyFor('alice@example.org');
        $options = $this->challenge()->json('data.publicKey');
        $assertion = $this->authenticator->assert($credential, $options, self::HOST);
        $assertion['response']['signature'] = VirtualAuthenticator::base64UrlEncode(random_bytes(71));

        $this->submit($assertion)->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/sign-in-failed');

        $this->assertSame('passkeyVerificationFailed', $this->onlyAuditRecord('signIn')->failure_reason);
    }

    public function test_a_passkey_of_another_host_name_fails(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $credential = $this->passkeyFor('alice@example.org', rpId: 'other.example.org');
        $options = $this->challenge()->json('data.publicKey');

        // The authenticator signs for the host name the passkey is bound to.
        $assertion = $this->authenticator->assert($credential, ['rpId' => 'other.example.org'] + $options, self::HOST);

        $this->submit($assertion)->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/sign-in-failed');
        $this->assertSame('passkeyVerificationFailed', $this->onlyAuditRecord('signIn')->failure_reason);
    }

    public function test_an_invalid_assertion_body_is_refused(): void
    {
        $this->withoutRequestValidation();

        $errors = $this->postJson(self::HOST.'/api/v2/session/passkey-assertion', ['credential' => ['id' => 'not base64url!']], ['X-CSRF-Token' => $this->csrfToken()])
            ->assertUnprocessable()
            ->json('errors');

        $this->assertSame('invalidFormat', $errors['credential.id'][0]['code']);
        $this->assertSame(['format' => 'base64url'], $errors['credential.id'][0]['params']);
        $this->assertSame('required', $errors['credential.rawId'][0]['code']);
        $this->assertSame('required', $errors['credential.response.signature'][0]['code']);
    }
}
