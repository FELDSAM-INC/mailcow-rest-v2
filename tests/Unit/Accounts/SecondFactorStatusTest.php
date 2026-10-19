<?php

namespace Tests\Unit\Accounts;

use App\Services\Accounts\SecondFactorStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Feature 007 T008 (FR-008, research R2): the second-factor status over the TOTP, WebAuthn and
 * Yubico OTP entries of an account; deprecated U2F and HOTP entries never count.
 */
class SecondFactorStatusTest extends TestCase
{
    /**
     * @param  list<array{string, int}>  $rows  `[authmech, active]`
     */
    #[DataProvider('cases')]
    public function test_the_status_of_the_entries(array $rows, SecondFactorStatus $expected): void
    {
        $this->assertSame($expected, SecondFactorStatus::fromTfaRows($rows));
    }

    /**
     * @return array<string, array{list<array{string, int}>, SecondFactorStatus}>
     */
    public static function cases(): array
    {
        return [
            'no entry' => [[], SecondFactorStatus::None],
            'an active TOTP entry' => [[['totp', 1]], SecondFactorStatus::Active],
            'an active WebAuthn entry' => [[['webauthn', 1]], SecondFactorStatus::Active],
            'an active Yubico OTP entry' => [[['yubi_otp', 1]], SecondFactorStatus::Active],
            'one active of several' => [[['totp', 0], ['webauthn', 1]], SecondFactorStatus::Active],
            'all inactive' => [[['totp', 0], ['yubi_otp', 0]], SecondFactorStatus::Suspended],
            'only an active U2F entry' => [[['u2f', 1]], SecondFactorStatus::None],
            'only HOTP entries' => [[['hotp', 1], ['hotp', 0]], SecondFactorStatus::None],
            'an inactive TOTP and an active U2F entry' => [[['totp', 0], ['u2f', 1]], SecondFactorStatus::Suspended],
        ];
    }

    public function test_the_values_are_those_of_the_contract(): void
    {
        $this->assertSame(['none', 'active', 'suspended'], array_map(fn (SecondFactorStatus $s): string => $s->value, SecondFactorStatus::cases()));
    }
}
