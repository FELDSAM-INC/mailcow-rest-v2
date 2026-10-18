<?php

namespace Tests\Feature\Performance;

use App\Support\Totp\NoQrCodeProvider;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Group;
use RobThree\Auth\TwoFactorAuth;
use Tests\Support\Auth\AuthRequests;
use Tests\Support\WebAuthn\VirtualAuthenticator;
use Tests\TestCase;

/**
 * T107 (SC-007): 95% of sign-in steps answer within 1 second, apart from deliberate throttling
 * delays (the test's Sleeper records them instead of waiting). Passwords use BLF-CRYPT with cost
 * 10, as mailcow writes them.
 */
#[Group('performance')]
class SignInLatencyTest extends TestCase
{
    use AuthRequests;

    private const ITERATIONS = 20;

    private const HOST = 'https://mail.example.org';

    /**
     * @param  list<float>  $seconds
     */
    private function assertP95Below(array $seconds, float $limit, string $step): void
    {
        sort($seconds);
        $p95 = $seconds[(int) ceil(0.95 * count($seconds)) - 1];

        $this->assertLessThan($limit, $p95, sprintf('%s: p95 %.3f s', $step, $p95));
    }

    private function timed(callable $request): float
    {
        $start = hrtime(true);
        $request();

        return (hrtime(true) - $start) / 1e9;
    }

    public function test_a_password_sign_in_without_second_factor(): void
    {
        $this->mailcow()->admin('admin', 'Passw0rd!');
        $durations = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $this->forgetCookies();
            $token = $this->csrfToken();
            $durations[] = $this->timed(fn () => $this->postJson('/api/v2/session', ['username' => 'admin', 'password' => 'Passw0rd!'], ['X-CSRF-Token' => $token])->assertCreated());
        }

        $this->assertP95Below($durations, 1.0, 'createSession');
    }

    public function test_a_password_sign_in_with_a_totp_second_factor(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $totpId = $this->mailcow()->tfaTotp('alice@example.org', 'JBSWY3DPEHPK3PXP');
        $password = [];
        $secondFactor = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // A new time slice for every code (codes are accepted once).
            $this->travel(31)->seconds();
            $this->forgetCookies();
            $token = $this->csrfToken();
            $password[] = $this->timed(fn () => $this->postJson('/api/v2/session', ['username' => 'alice@example.org', 'password' => 'Passw0rd!'], ['X-CSRF-Token' => $token])->assertCreated());
            $code = (new TwoFactorAuth(new NoQrCodeProvider))->getCode('JBSWY3DPEHPK3PXP', CarbonImmutable::now()->getTimestamp());
            $secondFactor[] = $this->timed(fn () => $this->postJson('/api/v2/session/pending-sign-in/second-factor', ['type' => 'totp', 'methodId' => $totpId, 'code' => $code], ['X-CSRF-Token' => $token])->assertOk());
        }

        $this->assertP95Below($password, 1.0, 'createSession (pending)');
        $this->assertP95Below($secondFactor, 1.0, 'verifySecondFactor');
    }

    public function test_a_passkey_sign_in(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $authenticator = new VirtualAuthenticator;
        $credential = $authenticator->createCredential('mail.example.org', 'alice@example.org');
        $this->mailcow()->fido2Credential('alice@example.org', $credential);
        $durations = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $this->forgetCookies();
            $token = $this->csrfToken();
            $options = $this->postEmpty(self::HOST.'/api/v2/session/passkey-challenge', ['X-CSRF-Token' => $token])->assertOk()->json('data.publicKey');
            $assertion = $authenticator->assert($credential, $options, self::HOST);
            $durations[] = $this->timed(fn () => $this->postJson(self::HOST.'/api/v2/session/passkey-assertion', ['credential' => $assertion], ['X-CSRF-Token' => $token])->assertCreated());
        }

        $this->assertP95Below($durations, 1.0, 'createSessionWithPasskey');
    }
}
