<?php

namespace Tests\Feature\SecondFactors;

use App\Auth\Role;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\Support\WebAuthn\VirtualAuthenticator;
use Tests\TestCase;

/**
 * T038 (feature 005 FR-031, US2 AS-3): WebAuthn second-factor registration options and their
 * completion, stored as legacy set_tfa('webauthn') stores the security key.
 */
class WebAuthnSecondFactorEnrolmentTest extends TestCase
{
    use SelfServiceTestHelpers;

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
        return $this->postEmpty(self::HOST.'/api/v2/me/second-factors/webauthn-registration-options', $this->csrf());
    }

    /**
     * @param  array<string, mixed>  $credential
     * @return TestResponse<Response>
     */
    private function enrol(array $credential, string $name = 'Security key', ?string $currentPassword = MailcowFactory::PASSWORD): TestResponse
    {
        $body = ['type' => 'webauthn', 'name' => $name, 'credential' => $credential];

        if ($currentPassword !== null) {
            $body['currentPassword'] = $currentPassword;
        }

        return $this->postJson(self::HOST.'/api/v2/me/second-factors', $body, $this->csrf());
    }

    public function test_the_options_exclude_the_existing_security_keys_and_follow_the_settings(): void
    {
        $this->freezeTime();
        $this->mailcow()->domainAdmin('reseller', ['example.org']);
        $existing = $this->authenticator->createCredential('mail.example.org', 'reseller');
        $this->mailcow()->tfaWebAuthnCredential('reseller', $existing);
        $passkey = $this->authenticator->createCredential('mail.example.org', 'reseller');
        $this->mailcow()->fido2Credential('reseller', $passkey);
        $this->signInAs('reseller', Role::DomainAdmin);

        $response = $this->registrationOptions()->assertOk();

        $options = $response->json('data.publicKey');
        $this->assertSame('mail.example.org', $options['rp']['id']);
        $this->assertSame(VirtualAuthenticator::base64UrlEncode('reseller'), $options['user']['id']);
        $this->assertSame('reseller', $options['user']['name']);
        $this->assertSame('preferred', $options['authenticatorSelection']['userVerification']);
        $this->assertNotSame('required', $options['authenticatorSelection']['residentKey'] ?? null);
        $this->assertSame([VirtualAuthenticator::base64UrlEncode($existing->id)], array_column($options['excludeCredentials'], 'id'));
        $this->assertSame(300000, $options['timeout']);
        $response->assertJsonPath('data.expiresAt', CarbonImmutable::now('UTC')->addSeconds(300)->format('Y-m-d\TH:i:s\Z'));

        config(['restv2.vars_overrides.WEBAUTHN_UV_FLAG_REGISTER' => 'required']);
        $this->assertSame('required', $this->registrationOptions()->json('data.publicKey.authenticatorSelection.userVerification'));
    }

    public function test_a_new_key_is_stored_as_legacy_and_completes_the_second_factor_step(): void
    {
        $this->mailcow()->domainAdmin('reseller', ['example.org']);
        $this->signInAs('reseller', Role::DomainAdmin);
        $options = $this->registrationOptions()->assertOk()->json('data.publicKey');
        $credential = $this->authenticator->attest($options, self::HOST, false);

        $response = $this->enrol($credential)->assertCreated()->assertJsonPath('data.type', 'webauthn');

        $row = DB::table('tfa')->where('id', (int) $response->json('data.id'))->first();
        $this->assertNotNull($row);
        $key = $this->authenticator->lastCredential();
        $this->assertSame(['reseller', 'Security key', 'webauthn', base64_encode($key->id), 0, 1], [$row->username, $row->key_id, $row->authmech, $row->keyHandle, (int) $row->counter, (int) $row->active]);
        $this->assertStringStartsWith('-----BEGIN PUBLIC KEY-----', (string) $row->publicKey);

        // The entry completes 001's second-factor step.
        $this->forgetCookies();
        $this->passwordSignIn('reseller')->assertCreated()->assertJsonPath('data.state', 'pendingSecondFactor');
        $challenge = $this->postEmpty(self::HOST.'/api/v2/session/pending-sign-in/webauthn-challenge', ['X-CSRF-Token' => $this->csrfToken()])->assertOk()->json('data.publicKey');
        $assertion = $this->authenticator->assert($key, $challenge, self::HOST, false);
        $this->postJson(self::HOST.'/api/v2/session/pending-sign-in/second-factor', ['type' => 'webauthn', 'credential' => $assertion], ['X-CSRF-Token' => $this->csrfToken()])
            ->assertOk()
            ->assertJsonPath('data.state', 'active');

        $this->assertSame('webauthn', $this->onlyAuditRecord('ownSecondFactorEnrolment')->details['secondFactorType']);
    }

    public function test_used_or_expired_options_are_refused(): void
    {
        $this->asSelfServiceUser();
        $options = $this->registrationOptions()->json('data.publicKey');
        $this->enrol($this->authenticator->attest($options, self::HOST))->assertCreated();

        $this->enrol($this->authenticator->attest($options, self::HOST))
            ->assertStatus(400)
            ->assertJsonPath('type', '/api/v2/problems/second-factor-enrolment-expired');

        $options = $this->registrationOptions()->json('data.publicKey');
        $this->travelPastChallengeTtl();
        $this->enrol($this->authenticator->attest($options, self::HOST))
            ->assertStatus(400)
            ->assertJsonPath('type', '/api/v2/problems/second-factor-enrolment-expired');
    }

    public function test_a_response_to_another_challenge_fails_and_uses_up_the_options(): void
    {
        $this->asSelfServiceUser();
        $stale = $this->registrationOptions()->json('data.publicKey');
        $current = $this->registrationOptions()->json('data.publicKey');

        $this->enrol($this->authenticator->attest($stale, self::HOST))
            ->assertStatus(400)
            ->assertJsonPath('type', '/api/v2/problems/second-factor-enrolment-failed');
        $this->enrol($this->authenticator->attest($current, self::HOST))
            ->assertStatus(400)
            ->assertJsonPath('type', '/api/v2/problems/second-factor-enrolment-expired');
        $this->assertSame(0, DB::table('tfa')->count());
    }

    public function test_the_same_key_may_also_be_a_passkey(): void
    {
        $this->asSelfServiceUser();
        $options = $this->registrationOptions()->json('data.publicKey');
        $this->enrol($this->authenticator->attest($options, self::HOST))->assertCreated();
        $key = $this->authenticator->lastCredential();
        $this->mailcow()->fido2Credential('anna@example.org', $key);

        $this->assertSame(1, DB::table('tfa')->where('username', 'anna@example.org')->count());
        $this->assertSame(1, DB::table('fido2')->where('username', 'anna@example.org')->count());
    }
}
