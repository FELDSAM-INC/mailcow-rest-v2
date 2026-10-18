<?php

namespace Tests\Feature\Auth;

use App\Support\Totp\NoQrCodeProvider;
use Carbon\CarbonImmutable;
use RobThree\Auth\TwoFactorAuth;
use Tests\Support\Auth\AuthRequests;
use Tests\TestCase;

/**
 * T060 (FR-020, FR-024): a pending sign-in is visible in the session, expires after 5 minutes and
 * can be cancelled.
 */
class PendingSignInLifecycleTest extends TestCase
{
    use AuthRequests;

    private const SECRET = 'JBSWY3DPEHPK3PXP';

    private int $methodId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $this->methodId = $this->mailcow()->tfaTotp('alice@example.org', self::SECRET, 'Phone');
    }

    private function code(): string
    {
        return (new TwoFactorAuth(new NoQrCodeProvider))->getCode(self::SECRET, CarbonImmutable::now()->getTimestamp());
    }

    public function test_the_session_shows_the_pending_sign_in(): void
    {
        $this->freezeTime();
        $this->passwordSignIn('alice@example.org')->assertCreated();

        $this->getJson('/api/v2/session')
            ->assertOk()
            ->assertJsonPath('data.state', 'pendingSecondFactor')
            ->assertJsonPath('data.username', 'alice@example.org')
            ->assertJsonPath('data.pendingSignIn.methods', [['id' => $this->methodId, 'type' => 'totp', 'name' => 'Phone', 'tier' => 'primary']])
            ->assertJsonPath('data.pendingSignIn.expiresAt', CarbonImmutable::now('UTC')->addSeconds(300)->format('Y-m-d\TH:i:s\Z'));
    }

    public function test_the_pending_sign_in_expires_after_five_minutes(): void
    {
        $this->passwordSignIn('alice@example.org')->assertCreated();

        $this->travelPastChallengeTtl();

        $this->verifySecondFactor(['type' => 'totp', 'methodId' => $this->methodId, 'code' => $this->code()])
            ->assertUnauthorized()
            ->assertJsonPath('type', '/api/v2/problems/sign-in-expired');
        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'anonymous');
        $this->assertSame('challengeExpired', $this->onlyAuditRecord('signIn')->failure_reason);
    }

    public function test_a_pending_sign_in_can_be_cancelled_keeping_the_token(): void
    {
        $this->passwordSignIn('alice@example.org')->assertCreated();
        $token = $this->csrfToken();

        $this->deleteJson('/api/v2/session/pending-sign-in', [], ['X-CSRF-Token' => $token])->assertNoContent();

        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'anonymous');
        $this->assertSame($token, $this->csrfToken());
        $this->verifySecondFactor(['type' => 'totp', 'methodId' => $this->methodId, 'code' => $this->code()])
            ->assertUnauthorized()
            ->assertJsonPath('type', '/api/v2/problems/sign-in-expired');
    }

    public function test_cancelling_without_a_pending_sign_in_succeeds(): void
    {
        $token = $this->csrfToken();

        $this->deleteJson('/api/v2/session/pending-sign-in', [], ['X-CSRF-Token' => $token])->assertNoContent();

        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'anonymous');
    }

    public function test_cancelling_needs_the_anti_forgery_token(): void
    {
        $this->passwordSignIn('alice@example.org')->assertCreated();

        $this->deleteJson('/api/v2/session/pending-sign-in')
            ->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/csrf-token-mismatch');
        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'pendingSecondFactor');
    }

    public function test_a_new_password_sign_in_replaces_the_pending_one(): void
    {
        $this->mailcow()->admin('admin', 'Passw0rd!');
        $this->passwordSignIn('alice@example.org')->assertCreated()->assertJsonPath('data.state', 'pendingSecondFactor');

        $this->passwordSignIn('admin')->assertCreated()->assertJsonPath('data.state', 'active');

        $this->getJson('/api/v2/session')->assertJsonPath('data.username', 'admin')->assertJsonPath('data.state', 'active');
        $this->verifySecondFactor(['type' => 'totp', 'methodId' => $this->methodId, 'code' => $this->code()])
            ->assertUnauthorized()
            ->assertJsonPath('type', '/api/v2/problems/sign-in-expired');
    }

    public function test_a_pending_sign_in_cannot_use_signed_in_operations(): void
    {
        $this->passwordSignIn('alice@example.org')->assertCreated();

        // A new pending sign-in for the second factor of another account replaces it too.
        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'pendingSecondFactor');
        $this->assertArrayNotHasKey('authMethod', $this->getJson('/api/v2/session')->json('data'));
    }
}
