<?php

namespace Tests\Unit\Passwords;

use App\Services\Passwords\EffectivePasswordPolicy;
use App\Services\Passwords\PasswordPolicyChecker;
use PHPUnit\Framework\TestCase;

/**
 * Feature 003 T007 (FR-024, research R4): legacy password_check() against the effective policy,
 * reporting every unmet rule with its parameter. Lengths count bytes, as legacy's strlen().
 */
class PasswordPolicyCheckerTest extends TestCase
{
    private static function policy(int $length = 6, bool $letters = false, bool $digits = false, bool $special = false, bool $mixed = false): EffectivePasswordPolicy
    {
        return new EffectivePasswordPolicy($length, $letters, $digits, $special, $mixed);
    }

    public function test_every_unmet_rule_is_reported(): void
    {
        $violations = (new PasswordPolicyChecker)->violations('short', self::policy(10, digits: true));

        $this->assertSame([
            ['code' => 'tooShort', 'params' => ['min' => 10]],
            ['code' => 'missingCharacterClass', 'params' => ['characterClass' => 'digit']],
        ], $violations);
    }

    public function test_the_length_counts_bytes(): void
    {
        $checker = new PasswordPolicyChecker;

        // Four two-byte characters and one ASCII letter: 9 bytes, 5 characters.
        $this->assertSame([], $checker->violations('ääääa', self::policy(9)));
        $this->assertSame([['code' => 'tooShort', 'params' => ['min' => 10]]], $checker->violations('ääääa', self::policy(10)));
    }

    public function test_each_character_class(): void
    {
        $checker = new PasswordPolicyChecker;
        $missing = fn (string $password, EffectivePasswordPolicy $policy): array => array_map(
            fn (array $v): string => (string) $v['params']['characterClass'],
            $checker->violations($password, $policy),
        );

        $this->assertSame(['letter'], $missing('123456!', self::policy(letters: true)));
        $this->assertSame([], $missing('abcdef', self::policy(letters: true)));
        $this->assertSame(['digit'], $missing('abcdef', self::policy(digits: true)));
        $this->assertSame([], $missing('abc123', self::policy(digits: true)));
        $this->assertSame(['special'], $missing('abc123', self::policy(special: true)));
        $this->assertSame([], $missing('abc 123', self::policy(special: true)));
        $this->assertSame(['lowerAndUpper'], $missing('abcdef', self::policy(mixed: true)));
        $this->assertSame(['lowerAndUpper'], $missing('ABCDEF', self::policy(mixed: true)));
        $this->assertSame([], $missing('abcDEF', self::policy(mixed: true)));
        $this->assertSame(['letter', 'digit', 'special', 'lowerAndUpper'], $missing('', self::policy(0, true, true, true, true)));
    }

    public function test_a_password_meeting_every_rule_passes(): void
    {
        $this->assertSame([], (new PasswordPolicyChecker)->violations('Passw0rd!dev', self::policy(10, true, true, true, true)));
    }
}
