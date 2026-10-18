<?php

namespace Tests\Feature\PasswordReset;

use App\Auth\Role;
use App\Clients\Yubico\YubicoOtpVerifier;
use App\Clients\Yubico\YubicoVerificationResult;
use App\Support\Hashing\LegacyPasswordVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\Support\Totp\TestTotp;
use Tests\Support\WebAuthn\VirtualAuthenticator;
use Tests\Support\Yubico\FakeYubicoOtpVerifier;
use Tests\TestCase;

/**
 * T054 (feature 005 FR-024, FR-025, US3 AS-5, AS-6, AS-8): createPasswordResetWebAuthnChallenge
 * and completePasswordReset, with the second-factor step and legacy's side effects.
 */
class PasswordResetCompletionTest extends TestCase
{
    use SelfServiceTestHelpers;

    private const HOST = 'https://mail.example.org';

    private const TOKEN = '3F9A1C-0B7E22-9D4F10-A6C3E8-51B2D7';

    private const NEW_PASSWORD = 'N3w!Passw0rd';

    /**
     * @param  array<string, mixed>|null  $secondFactor
     * @return TestResponse<Response>
     */
    private function complete(string $token = self::TOKEN, string $password = self::NEW_PASSWORD, ?array $secondFactor = null): TestResponse
    {
        $body = ['token' => $token, 'newPassword' => $password];

        if ($secondFactor !== null) {
            $body['secondFactor'] = $secondFactor;
        }

        return $this->postJson(self::HOST.'/api/v2/password-resets/completion', $body, ['X-CSRF-Token' => $this->resetCsrf()]);
    }

    private function resetCsrf(): string
    {
        return (string) $this->getJson(self::HOST.'/api/v2/csrf-token')->assertOk()->json('data.token');
    }

    private function tokenValid(string $token = self::TOKEN): bool
    {
        return DB::table('reset_password')->where('token', $token)->exists();
    }

    public function test_a_reset_without_second_factor_sets_the_password_and_ends_every_session(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@example.org', ['force_pw_update' => '1']);
        $first = $this->signInAs('anna@example.org', Role::MailboxUser);
        $second = $this->signInAs('anna@example.org', Role::MailboxUser);
        $this->forgetCookies();
        $this->mailcow()->resetToken('anna@example.org', self::TOKEN);
        $this->mailcow()->resetToken('anna@example.org', 'AAAAAA-AAAAAA-AAAAAA-AAAAAA-AAAAAA');

        $this->complete()->assertOk()->assertExactJson(['data' => ['address' => 'anna@example.org']]);

        $row = $this->mailboxRow('anna@example.org');
        $this->assertNotNull($row);
        $this->assertStringStartsWith('{BLF-CRYPT}', (string) $row->password);
        $this->assertTrue((new LegacyPasswordVerifier)->verify((string) $row->password, self::NEW_PASSWORD));
        $this->assertSame('0', $this->mailboxAttributes('anna@example.org')['force_pw_update']);
        $this->assertSame(0, DB::table('reset_password')->where('username', 'anna@example.org')->count());
        $this->assertTrue(DB::table('_sogo_static_view')->where('c_uid', 'anna@example.org')->exists());
        $this->assertSame(1, $this->memcached()->flushCount());
        $this->assertFalse($this->sessionExists($first));
        $this->assertFalse($this->sessionExists($second));

        $record = $this->onlyAuditRecord('passwordResetCompletion');
        $this->assertSame('success', $record->outcome);
        $this->assertSame('anna@example.org', $record->target_id);
        $this->assertSame(['password' => 'changed', 'forcedChangeResolved' => true, 'sessionsEnded' => 2], $record->details);
        $this->assertStringNotContainsString(self::TOKEN, (string) json_encode($record));

        $this->forgetCookies();
        $this->passwordSignIn('anna@example.org', self::NEW_PASSWORD)->assertCreated()->assertJsonPath('data.state', 'active');
        $this->complete()->assertStatus(400)->assertJsonPath('type', '/api/v2/problems/invalid-reset-token');
    }

    public function test_a_totp_account_needs_a_valid_code(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $id = $this->mailcow()->tfaTotp('anna@example.org', 'JBSWY3DPEHPK3PXP', 'Phone');
        $this->mailcow()->resetToken('anna@example.org', self::TOKEN);

        $this->complete()
            ->assertStatus(401)
            ->assertJsonPath('type', '/api/v2/problems/second-factor-required')
            ->assertJsonPath('methods', [['id' => $id, 'type' => 'totp', 'name' => 'Phone', 'tier' => 'primary']]);
        $this->assertTrue($this->tokenValid());

        $this->complete(secondFactor: ['type' => 'totp', 'methodId' => $id, 'code' => TestTotp::code('JBSWY3DPEHPK3PXP') === '000000' ? '111111' : '000000'])
            ->assertStatus(401)
            ->assertJsonPath('type', '/api/v2/problems/second-factor-failed');
        $this->assertTrue($this->tokenValid());
        $this->assertSame(1, (int) Redis::connection('restv2')->command('get', ['secondfactor:'.hash('sha256', 'anna@example.org|127.0.0.1')]));
        $this->assertSame('secondFactorFailed', $this->onlyAuditRecord('passwordResetCompletion')->failure_reason);

        $code = TestTotp::code('JBSWY3DPEHPK3PXP');
        $this->complete(secondFactor: ['type' => 'totp', 'methodId' => $id, 'code' => $code])->assertOk();

        // A code accepted once is not accepted again.
        $this->mailcow()->resetToken('anna@example.org', 'BBBBBB-BBBBBB-BBBBBB-BBBBBB-BBBBBB');
        $this->complete('BBBBBB-BBBBBB-BBBBBB-BBBBBB-BBBBBB', secondFactor: ['type' => 'totp', 'methodId' => $id, 'code' => $code])
            ->assertStatus(401)
            ->assertJsonPath('type', '/api/v2/problems/second-factor-failed');
    }

    public function test_a_webauthn_assertion_must_answer_the_challenge_of_the_token_and_the_session(): void
    {
        $authenticator = new VirtualAuthenticator;
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $key = $authenticator->createCredential('mail.example.org', 'anna@example.org');
        $this->mailcow()->tfaWebAuthnCredential('anna@example.org', $key);
        $this->mailcow()->resetToken('anna@example.org', self::TOKEN);
        $this->mailcow()->resetToken('anna@example.org', 'AAAAAA-AAAAAA-AAAAAA-AAAAAA-AAAAAA');
        $challenge = fn (string $token): array => $this->postJson(self::HOST.'/api/v2/password-resets/webauthn-challenge', ['token' => $token], ['X-CSRF-Token' => $this->resetCsrf()])->assertOk()->json('data.publicKey');

        // A challenge issued for another token fails.
        $other = $challenge('AAAAAA-AAAAAA-AAAAAA-AAAAAA-AAAAAA');
        $this->complete(secondFactor: ['type' => 'webauthn', 'credential' => $authenticator->assert($key, $other, self::HOST)])
            ->assertStatus(401)->assertJsonPath('type', '/api/v2/problems/second-factor-failed');

        // An expired one fails.
        $expired = $challenge(self::TOKEN);
        $this->travelPastChallengeTtl();
        $this->complete(secondFactor: ['type' => 'webauthn', 'credential' => $authenticator->assert($key, $expired, self::HOST)])
            ->assertStatus(401)->assertJsonPath('type', '/api/v2/problems/second-factor-failed');

        // One from another browser session fails.
        $foreign = $challenge(self::TOKEN);
        $this->forgetCookies();
        $this->complete(secondFactor: ['type' => 'webauthn', 'credential' => $authenticator->assert($key, $foreign, self::HOST)])
            ->assertStatus(401)->assertJsonPath('type', '/api/v2/problems/second-factor-failed');

        $valid = $challenge(self::TOKEN);
        $this->complete(secondFactor: ['type' => 'webauthn', 'credential' => $authenticator->assert($key, $valid, self::HOST)])->assertOk();
    }

    public function test_a_yubico_otp_is_verified_and_an_outage_keeps_the_token(): void
    {
        $this->app->instance(YubicoOtpVerifier::class, $yubico = new FakeYubicoOtpVerifier(YubicoVerificationResult::TemporaryFailure));
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $this->mailcow()->tfaYubi('anna@example.org', '12345', 'a2V5', 'cccjgjgkhcbb');
        $this->mailcow()->resetToken('anna@example.org', self::TOKEN);
        $otp = 'cccjgjgkhcbbirdrfdnlnghhfgrtnnlgedjlftrbdeut';

        $this->complete(secondFactor: ['type' => 'yubiOtp', 'otp' => $otp])
            ->assertStatus(503)
            ->assertHeader('Retry-After')
            ->assertJsonPath('type', '/api/v2/problems/temporary-verification-failure');
        $this->assertTrue($this->tokenValid());

        $yubico->result = YubicoVerificationResult::Valid;
        $this->complete(secondFactor: ['type' => 'yubiOtp', 'otp' => $otp])->assertOk();
        $this->assertSame('12345', $yubico->calls[1]['clientId']);
    }

    public function test_only_deprecated_entries_cannot_complete_and_passkeys_need_no_second_factor(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $this->mailcow()->tfaU2f('anna@example.org');
        $this->mailcow()->tfaHotp('anna@example.org');
        $this->mailcow()->resetToken('anna@example.org', self::TOKEN);

        $this->complete()->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/second-factor-unavailable');
        $this->assertTrue($this->tokenValid());

        $this->mailcow()->mailboxWithDomain('bob@example.org');
        $this->mailcow()->fido2Credential('bob@example.org', (new VirtualAuthenticator)->createCredential('mail.example.org', 'bob@example.org'));
        $this->mailcow()->resetToken('bob@example.org', 'BBBBBB-BBBBBB-BBBBBB-BBBBBB-BBBBBB');

        $this->complete('BBBBBB-BBBBBB-BBBBBB-BBBBBB-BBBBBB')->assertOk();
    }

    public function test_tokens_are_shared_with_the_legacy_reset_page(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@example.org', ['recovery_email' => 'anna.private@example.net']);
        // As legacy reset_password('issue') inserts it: `created` from its default.
        DB::insert('INSERT INTO `reset_password` (`username`, `token`) VALUES (?, ?)', ['anna@example.org', self::TOKEN]);

        $this->complete()->assertOk();

        // A token issued here is accepted by legacy's reset_password('check') query, run verbatim.
        $this->configureResetMessage();
        $this->postJson(self::HOST.'/api/v2/password-resets', ['address' => 'anna@example.org'], ['X-CSRF-Token' => $this->resetCsrf()])->assertOk();
        $token = (string) DB::table('reset_password')->where('username', 'anna@example.org')->value('token');
        $legacy = DB::selectOne("SELECT `t1`.`username` FROM `reset_password` AS `t1` JOIN `mailbox` AS `t2` ON `t1`.`username` = `t2`.`username` WHERE `t1`.`token` = :token AND `t1`.`created` > DATE_SUB(NOW(), INTERVAL :lifetime MINUTE) AND `t2`.`active` = 1 AND `t2`.`authsource` = 'mailcow';", ['token' => preg_replace('/[^a-zA-Z0-9-]/', '', $token), 'lifetime' => 15]);
        $this->assertSame('anna@example.org', $legacy->username ?? null);
    }

    public function test_invalid_new_passwords_keep_the_token(): void
    {
        $this->passwordPolicy(10, numbers: true);
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $this->mailcow()->resetToken('anna@example.org', self::TOKEN);

        $this->complete(password: 'secret')->assertStatus(422)->assertJsonPath('errors.newPassword.0.code', 'tooShort');
        $this->complete(password: '{SSHA512}abcdefghij1')->assertStatus(422)->assertJsonPath('errors.newPassword.0.code', 'preHashedPassword');

        $this->assertTrue($this->tokenValid());
    }
}
