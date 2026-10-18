<?php

namespace Tests\Feature\Auth;

use App\Clients\Yubico\YubicoOtpVerifier;
use App\Clients\Yubico\YubicoVerificationResult;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Auth\AuthRequests;
use Tests\Support\Yubico\FakeYubicoOtpVerifier;
use Tests\TestCase;

/**
 * T059 (FR-021, FR-022, R11): Yubico OTP second factors, verified with the per-key credentials
 * stored in mailcow. The Yubico service is faked.
 */
class SecondFactorYubicoTest extends TestCase
{
    use AuthRequests;

    private const KEY_A = 'c2VjcmV0LWtleS1vZi1rZXktYQ==';

    private const KEY_B = 'c2VjcmV0LWtleS1vZi1rZXktYg==';

    private FakeYubicoOtpVerifier $yubico;

    protected function setUp(): void
    {
        parent::setUp();

        $this->yubico = new FakeYubicoOtpVerifier;
        $this->app->instance(YubicoOtpVerifier::class, $this->yubico);

        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $this->mailcow()->tfaYubi('alice@example.org', '11111', self::KEY_A, 'cccccccccccb', 'Key A');
        $this->mailcow()->tfaYubi('alice@example.org', '22222', self::KEY_B, 'cccccccccccd', 'Key B');
        $this->passwordSignIn('alice@example.org')->assertCreated()->assertJsonPath('data.state', 'pendingSecondFactor');
    }

    private function otp(string $modhex): string
    {
        return $modhex.'dteffujehknhfjbrjnlnldnhcujvddbi';
    }

    public function test_a_valid_otp_selects_the_key_by_its_public_id(): void
    {
        $otp = $this->otp('cccccccccccd');

        $this->verifySecondFactor(['type' => 'yubiOtp', 'otp' => $otp])
            ->assertOk()
            ->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.authMethod', 'passwordAndSecondFactor');

        $this->assertSame([['clientId' => '22222', 'key' => self::KEY_B, 'otp' => $otp]], $this->yubico->calls);
        $audit = $this->onlyAuditRecord('signIn');
        $this->assertSame('success', $audit->outcome);
        $this->assertSame('yubiOtp', $audit->details['secondFactorType']);
        $this->assertStringNotContainsString($otp, json_encode($audit));
    }

    public function test_an_unknown_public_id_fails(): void
    {
        $this->verifySecondFactor(['type' => 'yubiOtp', 'otp' => $this->otp('cccccccccccf')])
            ->assertUnauthorized()
            ->assertJsonPath('type', '/api/v2/problems/second-factor-failed');

        $this->assertSame([], $this->yubico->calls);
        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'anonymous');
    }

    public function test_a_rejected_otp_fails_and_counts(): void
    {
        $this->yubico->result = YubicoVerificationResult::Invalid;

        $this->verifySecondFactor(['type' => 'yubiOtp', 'otp' => $this->otp('cccccccccccb')])
            ->assertUnauthorized()
            ->assertJsonPath('type', '/api/v2/problems/second-factor-failed');

        $this->assertSame('1', Redis::connection('mailcow')->get('restv2:signin:'.hash('sha256', 'alice@example.org|127.0.0.1')));
        $this->assertSame('secondFactorFailed', $this->onlyAuditRecord('signIn')->failure_reason);
    }

    public function test_an_unreachable_service_is_a_temporary_failure_that_keeps_the_pending_sign_in(): void
    {
        $this->yubico->result = YubicoVerificationResult::TemporaryFailure;

        $this->verifySecondFactor(['type' => 'yubiOtp', 'otp' => $this->otp('cccccccccccb')])
            ->assertStatus(503)
            ->assertJsonPath('type', '/api/v2/problems/temporary-verification-failure')
            ->assertHeader('Retry-After', '30');

        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'pendingSecondFactor');
        $this->assertSame([], Redis::connection('mailcow')->keys('restv2:signin:*'));
        $audit = $this->onlyAuditRecord('signIn');
        $this->assertSame('failure', $audit->outcome);
        $this->assertSame('verificationUnavailable', $audit->failure_reason);

        // Another attempt can follow.
        $this->yubico->result = YubicoVerificationResult::Valid;
        $this->verifySecondFactor(['type' => 'yubiOtp', 'otp' => $this->otp('cccccccccccb')])->assertOk();
    }

    public function test_an_invalid_otp_format_is_refused(): void
    {
        $this->withoutRequestValidation();

        $this->verifySecondFactor(['type' => 'yubiOtp', 'otp' => 'too-short'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.otp.0.code', 'invalidFormat')
            ->assertJsonPath('errors.otp.0.params', ['format' => 'yubicoOtp']);

        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'pendingSecondFactor');
    }
}
