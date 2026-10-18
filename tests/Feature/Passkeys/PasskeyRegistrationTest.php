<?php

namespace Tests\Feature\Passkeys;

use App\Auth\Role;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Auth\AuthRequests;
use Tests\Support\WebAuthn\VirtualAuthenticator;
use Tests\TestCase;

/**
 * T070 (FR-052, FR-054, AS-6, AS-8, R9): registering an own passkey, stored in `fido2` exactly as
 * legacy `fido2('register')` stores it.
 */
class PasskeyRegistrationTest extends TestCase
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
    private function registrationOptions(): TestResponse
    {
        return $this->postEmpty(self::HOST.'/api/v2/me/passkeys/registration-options', ['X-CSRF-Token' => $this->csrfToken()]);
    }

    /**
     * @param  array<string, mixed>  $credential
     * @return TestResponse<Response>
     */
    private function register(array $credential, ?string $name = null): TestResponse
    {
        $body = ['credential' => $credential];

        if ($name !== null) {
            $body['name'] = $name;
        }

        return $this->postJson(self::HOST.'/api/v2/me/passkeys', $body, ['X-CSRF-Token' => $this->csrfToken()]);
    }

    public function test_the_registration_options(): void
    {
        $this->freezeTime();
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $existing = $this->authenticator->createCredential('mail.example.org', 'alice@example.org');
        $inactive = $this->authenticator->createCredential('mail.example.org', 'alice@example.org');
        $this->mailcow()->fido2Credential('alice@example.org', $existing);
        $this->mailcow()->fido2Credential('alice@example.org', $inactive, active: 0);
        $this->mailcow()->mailboxWithDomain('bob@example.org');
        $this->mailcow()->fido2Credential('bob@example.org', $this->authenticator->createCredential('mail.example.org', 'bob@example.org'));
        $this->signInAs('alice@example.org', Role::MailboxUser);

        $response = $this->registrationOptions()->assertOk();

        $options = $response->json('data.publicKey');
        $this->assertEquals(['id' => 'mail.example.org', 'name' => 'mail.example.org - mail UI'], $options['rp']);
        $this->assertSame(VirtualAuthenticator::base64UrlEncode('alice@example.org'), $options['user']['id']);
        $this->assertSame('alice@example.org', $options['user']['name']);
        $this->assertSame('alice@example.org', $options['user']['displayName']);
        $this->assertSame('required', $options['authenticatorSelection']['residentKey']);
        $this->assertTrue($options['authenticatorSelection']['requireResidentKey']);
        $this->assertSame('preferred', $options['authenticatorSelection']['userVerification']);
        // Legacy's conveyance (orchestrator decision): `indirect`, which shows no privacy prompt.
        $this->assertSame('indirect', $options['attestation']);
        $this->assertSame(300000, $options['timeout']);
        $this->assertEqualsCanonicalizing(
            [VirtualAuthenticator::base64UrlEncode($existing->id), VirtualAuthenticator::base64UrlEncode($inactive->id)],
            array_column($options['excludeCredentials'], 'id'),
        );
        $response->assertJsonPath('data.expiresAt', CarbonImmutable::now('UTC')->addSeconds(300)->format('Y-m-d\TH:i:s\Z'));
    }

    public function test_the_options_follow_the_operator_settings(): void
    {
        config([
            'restv2.vars_overrides.FIDO2_FORMATS' => '["none"]',
            'restv2.vars_overrides.FIDO2_UV_FLAG_REGISTER' => 'required',
        ]);
        Redis::connection('mailcow')->set('MAIN_NAME', 'Example &amp; Co Mail');
        $this->mailcow()->admin('admin', 'x');
        $this->signInAs('admin', Role::Admin);

        $options = $this->registrationOptions()->assertOk()->json('data.publicKey');

        $this->assertSame('none', $options['attestation']);
        $this->assertSame('required', $options['authenticatorSelection']['userVerification']);
        $this->assertSame('Example & Co Mail', $options['rp']['name']);
        $this->assertSame([], $options['excludeCredentials']);
    }

    public function test_only_trusted_vendors_ask_for_direct_attestation(): void
    {
        config(['restv2.webauthn_only_trusted_vendors' => true]);
        $this->mailcow()->admin('admin', 'x');
        $this->signInAs('admin', Role::Admin);

        $this->assertSame('direct', $this->registrationOptions()->assertOk()->json('data.publicKey.attestation'));
    }

    public function test_a_passkey_is_registered_like_legacy_and_signs_in(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $this->signInAs('alice@example.org', Role::MailboxUser);
        $options = $this->registrationOptions()->assertOk()->json('data.publicKey');
        $attestation = $this->authenticator->attest($options, self::HOST);
        $credential = $this->authenticator->lastCredential();

        $response = $this->register($attestation, 'MacBook')->assertCreated();

        $id = hash('sha256', $credential->id);
        $response->assertHeader('Location', '/api/v2/me/passkeys/'.$id)
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.name', 'MacBook')
            ->assertJsonPath('data.authenticatorDescription', null)
            ->assertJsonPath('data.hostName', 'mail.example.org')
            ->assertJsonPath('data.active', true);
        $this->assertSame($response->headers->get('ETag'), $response->json('data.etag'));

        $row = DB::table('fido2')->where('username', 'alice@example.org')->first();
        $this->assertNotNull($row);
        $this->assertSame('mail.example.org', $row->rpId);
        $this->assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $row->credentialPublicKey);
        $this->assertSame($credential->publicKeyPem, $row->credentialPublicKey);
        $this->assertSame($credential->id, $row->credentialId);
        $this->assertSame($credential->aaguid, $row->AAGUID);
        $this->assertSame(16, strlen((string) $row->AAGUID));
        $this->assertNull($row->signatureCounter);
        // lbuchs reports no certificate as empty strings, which legacy stores as they are.
        $this->assertSame('', $row->certificateSubject);
        $this->assertSame('', $row->certificateIssuer);
        $this->assertSame('MacBook', $row->friendlyName);
        $this->assertSame(1, (int) $row->active);
        $this->assertSame($id, DB::table('fido2')->selectRaw('SHA2(`credentialId`, 256) AS cid')->value('cid'));

        $audit = $this->onlyAuditRecord('passkeyRegistration');
        $this->assertSame('success', $audit->outcome);
        $this->assertSame('passkey', $audit->target_type);
        $this->assertSame($id, $audit->target_id);
        $this->assertSame('MacBook', $audit->target_name);
        $this->assertFalse($audit->details['restrictionLifted']);

        // The new passkey signs in.
        $this->deleteJson('/api/v2/session', [], ['X-CSRF-Token' => $this->csrfToken()])->assertNoContent();
        $challenge = $this->postEmpty(self::HOST.'/api/v2/session/passkey-challenge', ['X-CSRF-Token' => $this->csrfToken()])->json('data.publicKey');
        $this->postJson(self::HOST.'/api/v2/session/passkey-assertion', [
            'credential' => $this->authenticator->assert($credential, $challenge, self::HOST),
        ], ['X-CSRF-Token' => $this->csrfToken()])
            ->assertCreated()
            ->assertJsonPath('data.username', 'alice@example.org');
    }

    public function test_the_name_is_optional(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $this->signInAs('admin', Role::Admin);
        $options = $this->registrationOptions()->json('data.publicKey');

        $this->register($this->authenticator->attest($options, self::HOST))->assertCreated()->assertJsonPath('data.name', null);

        $this->assertNull(DB::table('fido2')->where('username', 'admin')->value('friendlyName'));
    }

    public function test_an_already_stored_credential_is_refused(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $this->mailcow()->mailboxWithDomain('bob@example.org');
        $this->signInAs('alice@example.org', Role::MailboxUser);
        $attestation = $this->authenticator->attest($this->registrationOptions()->json('data.publicKey'), self::HOST);
        $this->mailcow()->fido2Credential('bob@example.org', $this->authenticator->lastCredential());

        $this->register($attestation)->assertStatus(400)->assertJsonPath('type', '/api/v2/problems/passkey-registration-failed');

        $this->assertSame(0, DB::table('fido2')->where('username', 'alice@example.org')->count());
        $audit = $this->onlyAuditRecord('passkeyRegistration');
        $this->assertSame('failure', $audit->outcome);
        $this->assertSame('registrationFailed', $audit->failure_reason);
    }

    public function test_options_are_single_use(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $this->signInAs('admin', Role::Admin);
        $options = $this->registrationOptions()->json('data.publicKey');
        $this->register($this->authenticator->attest($options, self::HOST))->assertCreated();

        $this->register($this->authenticator->attest($options, self::HOST))
            ->assertStatus(400)
            ->assertJsonPath('type', '/api/v2/problems/passkey-registration-failed');

        $this->assertSame(1, DB::table('fido2')->where('username', 'admin')->count());
    }

    public function test_options_expire_after_five_minutes(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $this->signInAs('admin', Role::Admin);
        $options = $this->registrationOptions()->json('data.publicKey');

        $this->travelPastChallengeTtl();

        $this->register($this->authenticator->attest($options, self::HOST))
            ->assertStatus(400)
            ->assertJsonPath('type', '/api/v2/problems/passkey-registration-failed');
    }

    public function test_a_response_for_another_challenge_is_refused(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $this->signInAs('admin', Role::Admin);
        $options = $this->registrationOptions()->json('data.publicKey');
        $options['challenge'] = VirtualAuthenticator::base64UrlEncode(random_bytes(32));

        $this->register($this->authenticator->attest($options, self::HOST))
            ->assertStatus(400)
            ->assertJsonPath('type', '/api/v2/problems/passkey-registration-failed');
    }

    public function test_user_verification_is_required_by_the_default_setting(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $this->signInAs('admin', Role::Admin);
        $options = $this->registrationOptions()->json('data.publicKey');

        $this->register($this->authenticator->attest($options, self::HOST, uv: false))->assertStatus(400);
    }

    public function test_a_pending_sign_in_cannot_register(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $this->signInPending('alice@example.org', Role::MailboxUser);

        $this->registrationOptions()->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/second-factor-required');
        $this->postJson(self::HOST.'/api/v2/me/passkeys', ['credential' => $this->authenticator->attest([
            'rp' => ['id' => 'mail.example.org'], 'user' => ['id' => VirtualAuthenticator::base64UrlEncode('alice@example.org')], 'challenge' => 'AAAA',
        ], self::HOST)], ['X-CSRF-Token' => $this->csrfToken()])
            ->assertUnauthorized()
            ->assertJsonPath('type', '/api/v2/problems/second-factor-required');
    }

    public function test_without_a_session_registration_is_unauthenticated(): void
    {
        $this->registrationOptions()->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/unauthenticated');
    }

    public function test_registering_lifts_a_forced_second_factor_setup(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org', ['force_tfa' => '1']);
        $this->passwordSignIn('alice@example.org')->assertCreated()
            ->assertJsonPath('data.state', 'restricted')
            ->assertJsonPath('data.pendingActions', ['secondFactorSetup']);

        $options = $this->registrationOptions()->assertOk()->json('data.publicKey');
        $this->register($this->authenticator->attest($options, self::HOST), 'Phone')->assertCreated();

        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'active')->assertJsonPath('data.pendingActions', []);
        $this->assertTrue($this->onlyAuditRecord('passkeyRegistration')->details['restrictionLifted']);

        // Later sign-ins are no longer restricted.
        $this->deleteJson('/api/v2/session', [], ['X-CSRF-Token' => $this->csrfToken()])->assertNoContent();
        $this->passwordSignIn('alice@example.org')->assertCreated()->assertJsonPath('data.state', 'active');
    }

    public function test_a_pending_password_change_blocks_registration(): void
    {
        $this->mailcow()->mailboxWithDomain('bob@example.org', ['force_pw_update' => '1', 'force_tfa' => '1']);
        $this->signInAs('bob@example.org', Role::MailboxUser);

        $this->registrationOptions()->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/pending-action-required')
            ->assertJsonPath('pendingActions', ['passwordChange', 'secondFactorSetup']);
        $this->register($this->authenticator->attest([
            'rp' => ['id' => 'mail.example.org'], 'user' => ['id' => VirtualAuthenticator::base64UrlEncode('bob@example.org')], 'challenge' => 'AAAA',
        ], self::HOST))->assertForbidden()->assertJsonPath('type', '/api/v2/problems/pending-action-required');
    }

    public function test_registration_needs_the_anti_forgery_token(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $this->signInAs('admin', Role::Admin);

        $this->postEmpty(self::HOST.'/api/v2/me/passkeys/registration-options')
            ->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/csrf-token-mismatch');
    }

    public function test_an_invalid_name_is_refused(): void
    {
        $this->withoutRequestValidation();
        $this->mailcow()->admin('admin', 'x');
        $this->signInAs('admin', Role::Admin);
        $options = $this->registrationOptions()->json('data.publicKey');
        $attestation = $this->authenticator->attest($options, self::HOST);

        $this->register($attestation, '   ')->assertUnprocessable()->assertJsonPath('errors.name.0.code', 'required');
        $this->register($attestation, str_repeat('x', 256))->assertUnprocessable()->assertJsonPath('errors.name.0.code', 'tooLong');
        // Validation failures leave the options unused.
        $this->register($attestation, 'Key')->assertCreated();
    }
}
