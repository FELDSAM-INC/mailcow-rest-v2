<?php

namespace Tests\Unit\Passwords;

use App\Services\Passwords\EffectivePasswordPolicy;
use App\Services\Passwords\MailboxPasswordHasher;
use App\Services\Passwords\PasswordPolicyChecker;
use App\Support\Passwords\NewPasswordValidator;
use App\Support\Passwords\PreHashedPasswordRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * T015 (feature 005 FR-009, research R4): a new self-service password never starts with a
 * hash-scheme prefix that legacy hash_password() recognises, and meets the password policy with
 * feature 003's rule codes, every unmet rule reported at once.
 */
class NewPasswordValidatorTest extends TestCase
{
    private function validator(): NewPasswordValidator
    {
        return new NewPasswordValidator(new PasswordPolicyChecker);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function prefixes(): array
    {
        $cases = [];

        foreach (MailboxPasswordHasher::PRE_HASHED_SCHEMES as $scheme) {
            $cases[$scheme] = ['{'.$scheme.'}abcdef', $scheme];
            $cases[strtolower($scheme).' lower case'] = ['{'.strtolower($scheme).'}abcdef', strtoupper($scheme)];
        }

        return $cases;
    }

    #[DataProvider('prefixes')]
    public function test_every_hash_scheme_prefix_is_refused_with_its_scheme(string $password, string $scheme): void
    {
        $policy = new EffectivePasswordPolicy(64, true, true, true, true);

        $this->assertSame([['code' => 'preHashedPassword', 'params' => ['scheme' => $scheme]]], $this->validator()->violations($password, $policy));
        $this->assertSame($scheme, PreHashedPasswordRule::scheme($password));
    }

    public function test_policy_failures_use_feature_003_codes_all_at_once(): void
    {
        $policy = new EffectivePasswordPolicy(10, true, true, true, true);

        $this->assertSame([
            ['code' => 'tooShort', 'params' => ['min' => 10]],
            ['code' => 'missingCharacterClass', 'params' => ['characterClass' => 'digit']],
            ['code' => 'missingCharacterClass', 'params' => ['characterClass' => 'special']],
            ['code' => 'missingCharacterClass', 'params' => ['characterClass' => 'lowerAndUpper']],
        ], $this->validator()->violations('secret', $policy));
    }

    public function test_a_valid_password_passes(): void
    {
        $policy = new EffectivePasswordPolicy(10, true, true, true, true);

        $this->assertSame([], $this->validator()->violations('N3w!Passw0rd', $policy));
        $this->assertNull(PreHashedPasswordRule::scheme('N3w!Passw0rd'));
        $this->assertNull(PreHashedPasswordRule::scheme('{UNKNOWN}abc'));
    }
}
