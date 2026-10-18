<?php

namespace Tests\Support\Totp;

use App\Support\Totp\NoQrCodeProvider;
use Carbon\CarbonImmutable;
use RobThree\Auth\TwoFactorAuth;

/**
 * TOTP codes for tests, computed with legacy's library and parameters (6 digits, 30 s, SHA-1) at
 * the application's current time.
 */
final class TestTotp
{
    /**
     * The code of $secret at now + $steps × 30 seconds.
     */
    public static function code(string $secret, int $steps = 0): string
    {
        return (new TwoFactorAuth(new NoQrCodeProvider))->getCode($secret, CarbonImmutable::now()->getTimestamp() + 30 * $steps);
    }
}
