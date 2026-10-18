<?php

namespace Tests\Feature\Auth;

use App\Support\Totp\NoQrCodeProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RobThree\Auth\TwoFactorAuth;
use Tests\Support\Auth\AuthRequests;
use Tests\Support\Redis\ChannelSpy;
use Tests\Support\WebAuthn\VirtualAuthenticator;
use Tests\TestCase;

/**
 * T057 (FR-019–FR-025, FR-008, SC-001): the second-factor step with TOTP, the method list of the
 * pending sign-in, and the rules for failures.
 */
class SecondFactorTotpTest extends TestCase
{
    use AuthRequests;

    private const SECRET = 'JBSWY3DPEHPK3PXP';

    private const IP = '127.0.0.1';

    private function code(string $secret = self::SECRET): string
    {
        return (new TwoFactorAuth(new NoQrCodeProvider))->getCode($secret, CarbonImmutable::now()->getTimestamp());
    }

    private function failures(string $username = 'alice@example.org'): int
    {
        return (int) Redis::connection('mailcow')->get('restv2:signin:'.hash('sha256', $username.'|'.self::IP));
    }

    public function test_the_pending_sign_in_lists_the_usable_methods_in_order(): void
    {
        $this->freezeTime();
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $yubi = $this->mailcow()->tfaYubi('alice@example.org', '12345', base64_encode(random_bytes(20)), 'cccccccccccb', 'YubiKey 5');
        $totp = $this->mailcow()->tfaTotp('alice@example.org', self::SECRET, 'Phone');
        $credential = (new VirtualAuthenticator)->createCredential('mail.example.org', 'alice@example.org');
        $webauthn = $this->mailcow()->tfaWebAuthnCredential('alice@example.org', $credential, 'Security key');
        $totp2 = $this->mailcow()->tfaTotp('alice@example.org', self::SECRET, 'Tablet');
        $this->mailcow()->tfaU2f('alice@example.org');
        $this->mailcow()->tfaHotp('alice@example.org');

        $response = $this->passwordSignIn('alice@example.org')->assertCreated();

        $response->assertJsonPath('data.state', 'pendingSecondFactor')
            ->assertJsonPath('data.username', 'alice@example.org')
            ->assertJsonPath('data.role', 'mailboxUser')
            ->assertJsonPath('data.pendingActions', [])
            ->assertJsonPath('data.pendingSignIn.methods', [
                ['id' => $webauthn, 'type' => 'webauthn', 'name' => 'Security key', 'tier' => 'primary'],
                ['id' => $totp, 'type' => 'totp', 'name' => 'Phone', 'tier' => 'primary'],
                ['id' => $totp2, 'type' => 'totp', 'name' => 'Tablet', 'tier' => 'primary'],
                ['id' => $yubi, 'type' => 'yubiOtp', 'name' => 'YubiKey 5', 'tier' => 'advanced'],
            ])
            ->assertJsonPath('data.pendingSignIn.expiresAt', CarbonImmutable::now('UTC')->addSeconds(300)->format('Y-m-d\TH:i:s\Z'));
        $this->assertArrayNotHasKey('authMethod', $response->json('data'));
        $this->assertStringNotContainsString(self::SECRET, (string) $response->getContent());
        $this->assertStringNotContainsString('cccccccccccb', (string) $response->getContent());
        // No full session yet, nothing audited yet.
        $this->assertSame([], $this->auditRecords('signIn'));
    }

    public function test_the_password_step_renews_the_session_identifier(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $this->mailcow()->tfaTotp('alice@example.org', self::SECRET);
        $this->csrfToken();
        $before = $this->sessionCookie();

        $this->passwordSignIn('alice@example.org')->assertCreated();

        $this->assertNotSame($before, $this->sessionCookie());
        $this->assertFalse((bool) Redis::connection('mailcow')->exists('restv2:session:'.$before));
    }

    public function test_a_valid_code_completes_the_sign_in(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $id = $this->mailcow()->tfaTotp('alice@example.org', self::SECRET);
        $this->passwordSignIn('alice@example.org')->assertCreated();
        $pendingSession = $this->sessionCookie();

        $response = $this->verifySecondFactor(['type' => 'totp', 'methodId' => $id, 'code' => $this->code()])->assertOk();

        $response->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.username', 'alice@example.org')
            ->assertJsonPath('data.role', 'mailboxUser')
            ->assertJsonPath('data.authMethod', 'passwordAndSecondFactor');
        $this->assertNotSame($pendingSession, $this->sessionCookie());
        $this->assertFalse((bool) Redis::connection('mailcow')->exists('restv2:session:'.$pendingSession));
        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'active');

        $audit = $this->onlyAuditRecord('signIn');
        $this->assertSame('success', $audit->outcome);
        $this->assertSame('passwordAndSecondFactor', $audit->auth_method);
        $this->assertSame('totp', $audit->details['secondFactorType']);
        $this->assertSame('alice@example.org', $audit->actor_username);
        $this->assertSame('mailboxUser', $audit->actor_role);
        // The replay marker of the accepted time slice.
        $this->assertTrue((bool) Redis::connection('mailcow')->exists('restv2:totp:last:'.$id));
    }

    public function test_a_wrong_code_cancels_the_pending_sign_in_and_counts(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $id = $this->mailcow()->tfaTotp('alice@example.org', self::SECRET);
        $this->passwordSignIn('alice@example.org')->assertCreated();
        $channel = ChannelSpy::fail2ban();

        $wrong = $this->code() === '000000' ? '111111' : '000000';
        $this->verifySecondFactor(['type' => 'totp', 'methodId' => $id, 'code' => $wrong])
            ->assertUnauthorized()
            ->assertJsonPath('type', '/api/v2/problems/second-factor-failed');

        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'anonymous');
        $this->assertSame(1, $this->failures());
        $channel->assertNothingPublished();

        $audit = $this->onlyAuditRecord('signIn');
        $this->assertSame('failure', $audit->outcome);
        $this->assertSame('secondFactorFailed', $audit->failure_reason);
        $this->assertSame('totp', $audit->details['secondFactorType']);
        $this->assertSame('secondFactor', $audit->details['step']);
        $this->assertSame('alice@example.org', $audit->actor_username);

        // The pending sign-in is gone: even the right code no longer works.
        $this->verifySecondFactor(['type' => 'totp', 'methodId' => $id, 'code' => $this->code()])
            ->assertUnauthorized()
            ->assertJsonPath('type', '/api/v2/problems/sign-in-expired');
    }

    public function test_a_code_is_accepted_only_once(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $id = $this->mailcow()->tfaTotp('alice@example.org', self::SECRET);
        $code = $this->code();

        $this->passwordSignIn('alice@example.org')->assertCreated();
        $this->verifySecondFactor(['type' => 'totp', 'methodId' => $id, 'code' => $code])->assertOk();

        $this->travel(20)->seconds();
        $this->deleteJson('/api/v2/session', [], ['X-CSRF-Token' => $this->csrfToken()])->assertNoContent();
        $this->passwordSignIn('alice@example.org')->assertCreated();
        $this->verifySecondFactor(['type' => 'totp', 'methodId' => $id, 'code' => $code])
            ->assertUnauthorized()
            ->assertJsonPath('type', '/api/v2/problems/second-factor-failed');
    }

    public function test_a_method_of_another_account_or_of_another_type_fails(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $this->mailcow()->mailboxWithDomain('bob@example.org');
        $this->mailcow()->tfaTotp('alice@example.org', self::SECRET);
        $yubi = $this->mailcow()->tfaYubi('alice@example.org', '1', base64_encode('k'), 'cccccccccccb');
        $bobs = $this->mailcow()->tfaTotp('bob@example.org', 'KRSXG5CTMVRXEZLU');

        $this->passwordSignIn('alice@example.org')->assertCreated();
        $this->verifySecondFactor(['type' => 'totp', 'methodId' => $bobs, 'code' => $this->code('KRSXG5CTMVRXEZLU')])
            ->assertUnauthorized()
            ->assertJsonPath('type', '/api/v2/problems/second-factor-failed');
        $this->assertSame(1, $this->failures());

        // The next verified password resets the count (FR-007), the next failure counts again.
        $this->passwordSignIn('alice@example.org')->assertCreated();
        $this->assertSame(0, $this->failures());
        $this->verifySecondFactor(['type' => 'totp', 'methodId' => $yubi, 'code' => $this->code()])
            ->assertUnauthorized()
            ->assertJsonPath('type', '/api/v2/problems/second-factor-failed');
        $this->assertSame(1, $this->failures());
    }

    public function test_a_method_deactivated_after_the_password_step_fails_without_counting(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $id = $this->mailcow()->tfaTotp('alice@example.org', self::SECRET);
        $this->mailcow()->tfaTotp('alice@example.org', 'KRSXG5CTMVRXEZLU');
        $this->passwordSignIn('alice@example.org')->assertCreated();

        DB::table('tfa')->where('id', $id)->update(['active' => 0]);

        $this->verifySecondFactor(['type' => 'totp', 'methodId' => $id, 'code' => $this->code()])
            ->assertUnauthorized()
            ->assertJsonPath('type', '/api/v2/problems/second-factor-failed');
        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'anonymous');
        $this->assertSame(0, $this->failures());
    }

    public function test_only_deprecated_factors_make_the_sign_in_unavailable(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $this->mailcow()->tfaU2f('alice@example.org');
        $this->mailcow()->tfaHotp('alice@example.org');

        $this->passwordSignIn('alice@example.org')->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/second-factor-unavailable');

        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'anonymous');
        $audit = $this->onlyAuditRecord('signIn');
        $this->assertSame('secondFactorUnavailable', $audit->failure_reason);
        // The password was right: the throttle is reset, not counted.
        $this->assertSame(0, $this->failures());
    }

    public function test_an_invalid_code_format_is_refused_and_keeps_the_pending_sign_in(): void
    {
        $this->withoutRequestValidation();
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $id = $this->mailcow()->tfaTotp('alice@example.org', self::SECRET);
        $this->passwordSignIn('alice@example.org')->assertCreated();

        $this->verifySecondFactor(['type' => 'totp', 'methodId' => $id, 'code' => '12345'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0.code', 'invalidFormat')
            ->assertJsonPath('errors.code.0.params', ['format' => 'totpCode']);

        $this->verifySecondFactor(['type' => 'totp', 'code' => '123456'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.methodId.0.code', 'required');

        $this->verifySecondFactor(['type' => 'sms', 'code' => '123456'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.type.0.code', 'notInList');

        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'pendingSecondFactor');
        $this->assertSame(0, $this->failures());
        $this->verifySecondFactor(['type' => 'totp', 'methodId' => $id, 'code' => $this->code()])->assertOk();
    }
}
