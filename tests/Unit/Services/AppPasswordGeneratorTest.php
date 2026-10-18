<?php

namespace Tests\Unit\Services;

use App\Services\AppPasswords\AppPasswordGenerator;
use App\Services\Passwords\EffectivePasswordPolicy;
use App\Services\Passwords\PasswordPolicyChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * T062 (feature 005 FR-038, research R11): generated app passwords have max(32, policy minimum)
 * characters of legacy password_generate()'s alphabet, with every character class, and meet the
 * policy.
 */
class AppPasswordGeneratorTest extends TestCase
{
    /**
     * @return array<string, array{int}>
     */
    public static function lengths(): array
    {
        return ['default' => [6], 'thirty-two' => [32], 'forty' => [40], 'sixty-four' => [64]];
    }

    #[DataProvider('lengths')]
    public function test_the_password_meets_the_strictest_policy(int $minLength): void
    {
        $policy = new EffectivePasswordPolicy($minLength, true, true, true, true);
        $generator = new AppPasswordGenerator;

        for ($i = 0; $i < 50; $i++) {
            $password = $generator->generate($policy);

            $this->assertSame(max(32, $minLength), strlen($password));
            $this->assertMatchesRegularExpression('/[a-z]/', $password);
            $this->assertMatchesRegularExpression('/[A-Z]/', $password);
            $this->assertMatchesRegularExpression('/[0-9]/', $password);
            $this->assertMatchesRegularExpression('/[!@#$%^&*()?=]/', $password);
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9!@#$%^&*()?=]+$/', $password);
            $this->assertSame([], (new PasswordPolicyChecker)->violations($password, $policy));
        }
    }

    public function test_passwords_differ(): void
    {
        $generator = new AppPasswordGenerator;
        $policy = new EffectivePasswordPolicy(6, false, false, false, false);

        $this->assertNotSame($generator->generate($policy), $generator->generate($policy));
    }
}
