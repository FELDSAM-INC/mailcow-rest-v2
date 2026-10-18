<?php

namespace App\Services\Passwords;

/**
 * Legacy password_check() against the effective policy (feature 003 FR-024, research R4), reporting
 * every unmet rule as a validation violation: `tooShort` with the minimum length in bytes (legacy
 * counts `strlen()`), and `missingCharacterClass` with `letter` (`[A-Za-z]`), `digit` (`[0-9]`),
 * `special` (anything else) or `lowerAndUpper` (a lower-case and an upper-case letter).
 */
class PasswordPolicyChecker
{
    /**
     * @return list<array{code: string, params: array<string, int|string>}>
     */
    public function violations(string $password, EffectivePasswordPolicy $policy): array
    {
        $violations = [];

        if (strlen($password) < $policy->minLength) {
            $violations[] = ['code' => 'tooShort', 'params' => ['min' => $policy->minLength]];
        }

        $missing = [
            'letter' => $policy->requireLetters && preg_match('/[a-zA-Z]/', $password) !== 1,
            'digit' => $policy->requireDigits && preg_match('/\d/', $password) !== 1,
            'special' => $policy->requireSpecialCharacters && preg_match('/[^a-zA-Z\d]/', $password) !== 1,
            'lowerAndUpper' => $policy->requireMixedCase && (preg_match('/[a-z]/', $password) !== 1 || preg_match('/[A-Z]/', $password) !== 1),
        ];

        foreach (array_keys(array_filter($missing)) as $class) {
            $violations[] = ['code' => 'missingCharacterClass', 'params' => ['characterClass' => $class]];
        }

        return $violations;
    }
}
