<?php

namespace App\Services\AppPasswords;

use App\Services\Passwords\EffectivePasswordPolicy;

/**
 * A generated app password (feature 005 FR-038, research R11): legacy password_generate()'s
 * alphabet with the spec's length, max(32, the policy's minimum) characters from random_int(),
 * with at least one lower-case letter, upper-case letter, digit and special character of
 * `!@#$%^&*()?=`, shuffled with Fisher–Yates, so that it meets every policy.
 */
class AppPasswordGenerator
{
    private const LOWER = 'abcdefghijklmnopqrstuvwxyz';

    private const UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private const DIGITS = '0123456789';

    private const SPECIAL = '!@#$%^&*()?=';

    public function generate(EffectivePasswordPolicy $policy): string
    {
        $length = max(32, $policy->minLength);
        $all = self::LOWER.self::UPPER.self::DIGITS.self::SPECIAL;
        $characters = [self::pick(self::LOWER), self::pick(self::UPPER), self::pick(self::DIGITS), self::pick(self::SPECIAL)];

        while (count($characters) < $length) {
            $characters[] = self::pick($all);
        }

        for ($i = count($characters) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return implode('', $characters);
    }

    private static function pick(string $alphabet): string
    {
        return $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
}
