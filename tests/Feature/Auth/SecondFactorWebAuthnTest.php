<?php

namespace Tests\Feature\Auth;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Auth\AuthRequests;
use Tests\Support\WebAuthn\VirtualAuthenticator;
use Tests\Support\WebAuthn\VirtualCredential;
use Tests\TestCase;

/**
 * T058 (AS-3, FR-021, FR-022, R9): WebAuthn second factors registered in the legacy UI (`tfa`
 * rows with a base64 key handle and a PEM key) complete a pending sign-in.
 */
class SecondFactorWebAuthnTest extends TestCase
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
        return $this->postEmpty(self::HOST.'/api/v2/session/pending-sign-in/webauthn-challenge', ['X-CSRF-Token' => $this->csrfToken()]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return TestResponse<Response>
     */
    private function verifyWith(VirtualCredential $credential, array $options): TestResponse
    {
        return $this->postJson(self::HOST.'/api/v2/session/pending-sign-in/second-factor', [
            'type' => 'webauthn',
            'credential' => $this->authenticator->assert($credential, $options, self::HOST, uv: false),
        ], ['X-CSRF-Token' => $this->csrfToken()]);
    }

    private function credentialFor(string $username, int $active = 1): VirtualCredential
    {
        $credential = $this->authenticator->createCredential('mail.example.org', $username);
        $this->mailcow()->tfaWebAuthnCredential($username, $credential, 'Key of '.$username, $active);

        return $credential;
    }

    public function test_the_challenge_allows_the_active_webauthn_factors(): void
    {
        $this->freezeTime();
        $this->mailcow()->ensureDomain('example.org');
        $this->mailcow()->domainAdmin('reseller', ['example.org']);
        $first = $this->credentialFor('reseller');
        $second = $this->credentialFor('reseller');
        $this->mailcow()->tfaTotp('reseller', 'JBSWY3DPEHPK3PXP');
        $this->passwordSignIn('reseller')->assertCreated();
        // Deactivated after the password step: no longer allowed.
        $inactive = $this->credentialFor('reseller', 0);
        $this->mailcow()->mailboxWithDomain('bob@example.org');
        $this->credentialFor('bob@example.org');

        $response = $this->challenge()->assertOk();

        $options = $response->json('data.publicKey');
        $this->assertSame('mail.example.org', $options['rpId']);
        $this->assertSame('preferred', $options['userVerification']);
        $this->assertSame(300000, $options['timeout']);
        $this->assertSame(['appid' => 'https://mail.example.org'], $options['extensions']);
        $this->assertEqualsCanonicalizing(
            [VirtualAuthenticator::base64UrlEncode($first->id), VirtualAuthenticator::base64UrlEncode($second->id)],
            array_column($options['allowCredentials'], 'id'),
        );
        $this->assertNotContains(VirtualAuthenticator::base64UrlEncode($inactive->id), array_column($options['allowCredentials'], 'id'));
        $response->assertJsonPath('data.expiresAt', CarbonImmutable::now('UTC')->addSeconds(300)->format('Y-m-d\TH:i:s\Z'));
    }

    public function test_an_assertion_of_a_legacy_registered_key_completes_the_sign_in(): void
    {
        $this->mailcow()->ensureDomain('example.org');
        $this->mailcow()->domainAdmin('reseller', ['example.org']);
        $credential = $this->credentialFor('reseller');
        $this->passwordSignIn('reseller')->assertCreated()->assertJsonPath('data.state', 'pendingSecondFactor');

        $options = $this->challenge()->assertOk()->json('data.publicKey');
        $response = $this->verifyWith($credential, $options)->assertOk();

        $response->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.role', 'domainAdmin')
            ->assertJsonPath('data.authMethod', 'passwordAndSecondFactor');
        $audit = $this->onlyAuditRecord('signIn');
        $this->assertSame('success', $audit->outcome);
        $this->assertSame('webauthn', $audit->details['secondFactorType']);
    }

    public function test_user_verification_follows_the_operator_setting(): void
    {
        config(['restv2.vars_overrides.WEBAUTHN_UV_FLAG_LOGIN' => 'required']);
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $credential = $this->credentialFor('alice@example.org');
        $this->passwordSignIn('alice@example.org')->assertCreated();

        $options = $this->challenge()->assertOk()->json('data.publicKey');

        $this->assertSame('required', $options['userVerification']);
        // The virtual authenticator answers without user verification: refused.
        $this->verifyWith($credential, $options)->assertUnauthorized()
            ->assertJsonPath('type', '/api/v2/problems/second-factor-failed');
    }

    public function test_an_assertion_by_another_accounts_key_fails(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $this->mailcow()->mailboxWithDomain('bob@example.org');
        $this->credentialFor('alice@example.org');
        $bobs = $this->credentialFor('bob@example.org');
        $this->passwordSignIn('alice@example.org')->assertCreated();

        $options = $this->challenge()->assertOk()->json('data.publicKey');
        $this->verifyWith($bobs, $options)
            ->assertUnauthorized()
            ->assertJsonPath('type', '/api/v2/problems/second-factor-failed');

        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'anonymous');
        $this->assertSame('secondFactorFailed', $this->onlyAuditRecord('signIn')->failure_reason);
    }

    public function test_a_tampered_signature_fails(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $credential = $this->credentialFor('alice@example.org');
        $this->passwordSignIn('alice@example.org')->assertCreated();
        $options = $this->challenge()->assertOk()->json('data.publicKey');

        $assertion = $this->authenticator->assert($credential, $options, self::HOST, uv: false);
        $assertion['response']['signature'] = VirtualAuthenticator::base64UrlEncode(random_bytes(70));

        $this->postJson(self::HOST.'/api/v2/session/pending-sign-in/second-factor', ['type' => 'webauthn', 'credential' => $assertion], ['X-CSRF-Token' => $this->csrfToken()])
            ->assertUnauthorized()
            ->assertJsonPath('type', '/api/v2/problems/second-factor-failed');
    }

    public function test_the_challenge_is_single_use(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $credential = $this->credentialFor('alice@example.org');
        $other = $this->authenticator->createCredential('mail.example.org', 'alice@example.org');
        $this->passwordSignIn('alice@example.org')->assertCreated();
        $options = $this->challenge()->assertOk()->json('data.publicKey');

        $this->verifyWith($other, $options)->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/second-factor-failed');

        // After the failure, the challenge (and the pending sign-in) are gone.
        $this->verifyWith($credential, $options)->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/sign-in-expired');
    }

    public function test_a_verification_without_a_challenge_is_expired_and_keeps_the_pending_sign_in(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $credential = $this->credentialFor('alice@example.org');
        $this->passwordSignIn('alice@example.org')->assertCreated();

        $this->verifyWith($credential, ['challenge' => VirtualAuthenticator::base64UrlEncode(random_bytes(32)), 'rpId' => 'mail.example.org'])
            ->assertUnauthorized()
            ->assertJsonPath('type', '/api/v2/problems/sign-in-expired');

        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'pendingSecondFactor');
        $options = $this->challenge()->assertOk()->json('data.publicKey');
        $this->verifyWith($credential, $options)->assertOk();
    }

    public function test_the_challenge_expires_with_the_pending_sign_in(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $credential = $this->credentialFor('alice@example.org');
        $this->passwordSignIn('alice@example.org')->assertCreated();
        $options = $this->challenge()->assertOk()->json('data.publicKey');

        $this->travelPastChallengeTtl();

        $this->verifyWith($credential, $options)->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/sign-in-expired');
    }

    public function test_a_challenge_without_a_pending_sign_in_is_expired(): void
    {
        $this->challenge()->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/sign-in-expired');
    }

    public function test_a_challenge_without_webauthn_methods_is_a_conflict(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $this->mailcow()->tfaTotp('alice@example.org', 'JBSWY3DPEHPK3PXP');
        $this->passwordSignIn('alice@example.org')->assertCreated();

        $this->challenge()->assertStatus(409)->assertJsonPath('type', 'about:blank');
        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'pendingSecondFactor');
    }

    public function test_a_deleted_key_fails_without_counting(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $credential = $this->credentialFor('alice@example.org');
        $this->credentialFor('alice@example.org');
        $this->passwordSignIn('alice@example.org')->assertCreated();
        $options = $this->challenge()->assertOk()->json('data.publicKey');

        DB::table('tfa')->where('keyHandle', base64_encode($credential->id))->update(['active' => 0]);

        $this->verifyWith($credential, $options)->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/second-factor-failed');
        $this->assertSame([], Redis::connection('mailcow')->keys('restv2:signin:*'));
    }
}
