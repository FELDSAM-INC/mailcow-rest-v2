<?php

namespace Tests\Feature\Auth;

use App\Auth\Role;
use App\Support\Hashing\LegacyPasswordVerifier;
use App\Support\Totp\NoQrCodeProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use RobThree\Auth\TwoFactorAuth;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Auth\AuthRequests;
use Tests\Support\Hashing\SpyPasswordVerifier;
use Tests\Support\Redis\ChannelSpy;
use Tests\Support\WebAuthn\VirtualAuthenticator;
use Tests\TestCase;

/**
 * Orchestrator decision (FR-007, FR-024, parity table, security fix): second-factor failures have
 * their own count per username and client address, which a correct password does not reset. Only
 * a completed sign-in resets it. After 10 failures within 15 minutes, password steps and
 * second-factor steps of that pair are refused with `429` without checking credentials, so an
 * attacker who knows the password cannot guess second factors by repeating the password step.
 */
class SecondFactorThrottleTest extends TestCase
{
    use AuthRequests;

    private const SECRET = 'JBSWY3DPEHPK3PXP';

    private const IP = '192.0.2.10';

    private int $totpId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withServerVariables(['REMOTE_ADDR' => self::IP]);
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $this->totpId = $this->mailcow()->tfaTotp('alice@example.org', self::SECRET);
    }

    private function code(): string
    {
        return (new TwoFactorAuth(new NoQrCodeProvider))->getCode(self::SECRET, CarbonImmutable::now()->getTimestamp());
    }

    private function wrongCode(): string
    {
        return $this->code() === '000000' ? '111111' : '000000';
    }

    /**
     * @return TestResponse<Response>
     */
    private function totp(string $code): TestResponse
    {
        return $this->verifySecondFactor(['type' => 'totp', 'methodId' => $this->totpId, 'code' => $code]);
    }

    /**
     * Repeats "correct password, wrong second factor" $times times.
     */
    private function failSecondFactors(int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->passwordSignIn('alice@example.org')->assertCreated()->assertJsonPath('data.state', 'pendingSecondFactor');
            $this->totp($this->wrongCode())->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/second-factor-failed');
        }
    }

    private function secondFactorFailures(string $ip = self::IP): int
    {
        return (int) Redis::connection('mailcow')->get('restv2:secondfactor:'.hash('sha256', 'alice@example.org|'.$ip));
    }

    public function test_a_correct_password_does_not_reset_the_second_factor_count(): void
    {
        $this->failSecondFactors(10);

        $this->assertSame(10, $this->secondFactorFailures());
        // Each second-factor failure is delayed by the second-factor count; the password steps
        // in between were correct and are not delayed.
        $this->assertSame([0.0, 0.5, 1.0, 1.5, 2.0, 2.5, 3.0, 3.5, 4.0, 4.5], $this->sleeper()->slept);
    }

    public function test_after_ten_failures_the_password_step_is_refused_without_checking_the_password(): void
    {
        $this->failSecondFactors(10);
        $spy = new SpyPasswordVerifier;
        $this->app->instance(LegacyPasswordVerifier::class, $spy);
        $channel = ChannelSpy::fail2ban();

        $response = $this->passwordSignIn('alice@example.org')->assertStatus(429);

        $response->assertJsonPath('type', '/api/v2/problems/too-many-requests');
        $retryAfter = $response->json('retryAfter');
        $this->assertIsInt($retryAfter);
        $this->assertGreaterThanOrEqual(1, $retryAfter);
        $this->assertLessThanOrEqual(900, $retryAfter);
        $response->assertHeader('Retry-After', (string) $retryAfter);
        $this->assertSame([], $spy->verifiedHashes);
        // Like every throttled password attempt (R17): published and audited.
        $channel->assertPublished('mailcow UI: Invalid password for alice@example.org by '.self::IP);
        $records = $this->auditRecords('signIn');
        $this->assertSame('throttled', end($records)->failure_reason);
        $this->assertSame('password', end($records)->details['step']);
        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'anonymous');
    }

    public function test_after_ten_failures_an_open_pending_sign_in_is_refused_without_checking_the_factor(): void
    {
        // A pending sign-in opened in another session before the limit was reached.
        $other = $this->signInPending('alice@example.org', Role::MailboxUser, [$this->totpId]);
        $this->forgetCookies();
        $this->failSecondFactors(10);
        $this->forgetCookies();
        $this->useSessionCookie($other);

        $response = $this->totp($this->code())->assertStatus(429);

        $response->assertJsonPath('type', '/api/v2/problems/too-many-requests');
        $this->assertFalse((bool) Redis::connection('mailcow')->exists('restv2:totp:last:'.$this->totpId), 'the code must not be verified');
        $records = $this->auditRecords('signIn');
        $this->assertSame('throttled', end($records)->failure_reason);
        $this->assertSame('secondFactor', end($records)->details['step']);
    }

    public function test_a_completed_sign_in_resets_the_count(): void
    {
        $this->failSecondFactors(9);

        $this->passwordSignIn('alice@example.org')->assertCreated();
        $this->totp($this->code())->assertOk()->assertJsonPath('data.state', 'active');

        $this->assertSame(0, $this->secondFactorFailures());
        $this->forgetCookies();
        $this->failSecondFactors(1);
        $this->assertSame(0.0, end($this->sleeper()->slept));
    }

    public function test_a_passkey_sign_in_of_the_account_resets_the_count(): void
    {
        $authenticator = new VirtualAuthenticator;
        $credential = $authenticator->createCredential('mail.example.org', 'alice@example.org');
        $this->mailcow()->fido2Credential('alice@example.org', $credential);
        $this->failSecondFactors(9);

        $options = $this->postEmpty('https://mail.example.org/api/v2/session/passkey-challenge', ['X-CSRF-Token' => $this->csrfToken()])
            ->assertOk()->json('data.publicKey');
        $this->postJson('https://mail.example.org/api/v2/session/passkey-assertion', [
            'credential' => $authenticator->assert($credential, $options, 'https://mail.example.org'),
        ], ['X-CSRF-Token' => $this->csrfToken()])->assertCreated();

        $this->assertSame(0, $this->secondFactorFailures());
    }

    public function test_another_address_is_unaffected_and_the_window_expires(): void
    {
        $this->failSecondFactors(10);

        $this->forgetCookies();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11']);
        $this->passwordSignIn('alice@example.org')->assertCreated()->assertJsonPath('data.state', 'pendingSecondFactor');

        $this->forgetCookies();
        $this->withServerVariables(['REMOTE_ADDR' => self::IP]);
        $this->passwordSignIn('alice@example.org')->assertStatus(429);

        $this->travel(901)->seconds();

        $this->passwordSignIn('alice@example.org')->assertCreated()->assertJsonPath('data.state', 'pendingSecondFactor');
    }

    public function test_the_limit_follows_the_sign_in_throttling_settings(): void
    {
        config(['restv2.signin_max_failures' => 3, 'restv2.signin_failure_window' => 60]);

        $this->failSecondFactors(3);

        $this->passwordSignIn('alice@example.org')->assertStatus(429);
    }
}
