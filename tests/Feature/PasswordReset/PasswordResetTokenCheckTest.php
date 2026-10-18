<?php

namespace Tests\Feature\PasswordReset;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T053 (feature 005 FR-023, US3 AS-4): checkPasswordResetToken tells the holder of a valid token the
 * account, the expiry, the password rules and the second-factor situation; anything else is an
 * invalid token.
 */
class PasswordResetTokenCheckTest extends TestCase
{
    use SelfServiceTestHelpers;

    private const TOKEN = '3F9A1C-0B7E22-9D4F10-A6C3E8-51B2D7';

    /**
     * @return TestResponse<Response>
     */
    private function check(string $token): TestResponse
    {
        return $this->postJson('/api/v2/password-resets/token-check', ['token' => $token], ['X-CSRF-Token' => $this->csrfToken()]);
    }

    public function test_a_valid_token_describes_the_reset(): void
    {
        $this->freezeTime();
        $this->passwordPolicy(8, numbers: true);
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $totp = $this->mailcow()->tfaTotp('anna@example.org', 'JBSWY3DPEHPK3PXP', 'Phone');
        $yubi = $this->mailcow()->tfaYubi('anna@example.org', '1', 'a2V5', 'cccjgjgkhcbb', 'YubiKey');
        $this->mailcow()->tfaU2f('anna@example.org');
        $created = CarbonImmutable::now('Europe/Prague')->subMinutes(5);
        $this->mailcow()->resetToken('anna@example.org', self::TOKEN, $created->format('Y-m-d H:i:s'));

        $this->check(strtolower(self::TOKEN).' ')
            ->assertOk()
            ->assertJsonPath('data.address', 'anna@example.org')
            ->assertJsonPath('data.expiresAt', $created->addMinutes(15)->utc()->format('Y-m-d\TH:i:s\Z'))
            ->assertJsonPath('data.passwordPolicy.minLength', 8)
            ->assertJsonPath('data.passwordPolicy.requireDigits', true)
            ->assertJsonPath('data.secondFactorRequired', true)
            ->assertJsonPath('data.secondFactorMethods', [
                ['id' => $totp, 'type' => 'totp', 'name' => 'Phone', 'tier' => 'primary'],
                ['id' => $yubi, 'type' => 'yubiOtp', 'name' => 'YubiKey', 'tier' => 'advanced'],
            ]);

        $this->assertSame(1, DB::table('reset_password')->count());
        $this->assertSame([], $this->auditRecords('passwordResetRequest'));
    }

    public function test_an_account_without_second_factors_needs_none(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $this->mailcow()->resetToken('anna@example.org', self::TOKEN);

        $this->check(self::TOKEN)->assertOk()->assertJsonPath('data.secondFactorRequired', false)->assertJsonPath('data.secondFactorMethods', []);
    }

    public function test_unknown_expired_inactive_and_external_tokens_are_invalid(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $this->mailcow()->resetToken('anna@example.org', 'AAAAAA-AAAAAA-AAAAAA-AAAAAA-AAAAAA', date('Y-m-d H:i:s', time() - 16 * 60));
        $this->mailcow()->mailbox('inactive@example.org', active: 0);
        $this->mailcow()->resetToken('inactive@example.org', 'BBBBBB-BBBBBB-BBBBBB-BBBBBB-BBBBBB');
        $this->mailcow()->mailbox('ldap@example.org', authsource: 'ldap');
        $this->mailcow()->resetToken('ldap@example.org', 'CCCCCC-CCCCCC-CCCCCC-CCCCCC-CCCCCC');

        foreach (['DDDDDD-DDDDDD-DDDDDD-DDDDDD-DDDDDD', 'AAAAAA-AAAAAA-AAAAAA-AAAAAA-AAAAAA', 'BBBBBB-BBBBBB-BBBBBB-BBBBBB-BBBBBB', 'CCCCCC-CCCCCC-CCCCCC-CCCCCC-CCCCCC', '%'] as $token) {
            $this->check($token)->assertStatus(400)->assertJsonPath('type', '/api/v2/problems/invalid-reset-token');
        }
    }
}
