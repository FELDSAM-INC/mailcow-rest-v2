<?php

namespace App\Support\Passwords;

use App\Clients\SharedStore\SharedStoreUnavailable;
use App\Services\Passwords\EffectivePasswordPolicy;
use App\Services\Passwords\PasswordPolicyChecker;
use App\Services\Passwords\PasswordPolicyReader;
use App\Support\Http\FieldViolations;

/**
 * The rules of every new password of feature 005, own passwords, reset passwords and app passwords
 * (FR-009, research R4): no hash-scheme prefix (`preHashedPassword` with `scheme`), otherwise the
 * password policy with feature 003's codes (`tooShort` with `min`, one `missingCharacterClass` per
 * unmet class), every unmet rule reported at once. A prefixed value is not policy-checked.
 */
class NewPasswordValidator
{
    public function __construct(
        private readonly PasswordPolicyChecker $checker,
        private readonly ?PasswordPolicyReader $policies = null,
    ) {}

    /**
     * @return list<array{code: string, params: array<string, int|string>}>
     */
    public function violations(string $password, EffectivePasswordPolicy $policy): array
    {
        $scheme = PreHashedPasswordRule::scheme($password);

        if ($scheme !== null) {
            return [['code' => 'preHashedPassword', 'params' => ['scheme' => $scheme]]];
        }

        return $this->checker->violations($password, $policy);
    }

    /**
     * Adds the violations of the password under $field, with the policy in force.
     *
     * @throws SharedStoreUnavailable when the policy cannot be read
     */
    public function validate(string $password, FieldViolations $violations, string $field = 'newPassword'): void
    {
        $policy = ($this->policies ?? app(PasswordPolicyReader::class))->effective();

        foreach ($this->violations($password, $policy) as $violation) {
            $message = $violation['code'] === 'preHashedPassword'
                ? 'The password must not start with a hash-scheme prefix such as {'.$violation['params']['scheme'].'}.'
                : null;
            $violations->add($field, $violation['code'], $violation['params'], $message);
        }
    }
}
