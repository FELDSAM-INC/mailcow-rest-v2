<?php

namespace App\Http\Requests\Api\V2;

use App\Http\Requests\Api\V2\Concerns\WebAuthnCredentialRules;
use App\Rules\MatchesFormat;

/**
 * Body of `completePasswordReset` (`PasswordResetCompletionRequest`, feature 005 FR-024): the token,
 * the new password (its rules are the service's) and, for accounts with second factors, a
 * `SecondFactorVerification`. Completions are limited per client address (FR-026).
 */
class CompletePasswordResetRequest extends CheckPasswordResetTokenRequest
{
    use WebAuthnCredentialRules;

    protected string $throttleStep = 'complete';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'token' => ['bail', 'required', 'string', 'min:1', 'max:64'],
            'newPassword' => ['bail', 'required', 'string', 'min:1', 'max:4096'],
            'secondFactor' => ['bail', 'sometimes', 'array'],
            'secondFactor.type' => ['bail', 'required_with:secondFactor', 'string', 'in:totp,webauthn,yubiOtp'],
            'secondFactor.methodId' => ['bail', 'exclude_unless:secondFactor.type,totp', 'required', 'integer', 'min:1'],
            'secondFactor.code' => ['bail', 'exclude_unless:secondFactor.type,totp', 'required', 'string', new MatchesFormat('/^[0-9]{6}$/', 'totpCode')],
            'secondFactor.otp' => ['bail', 'exclude_unless:secondFactor.type,yubiOtp', 'required', 'string', new MatchesFormat('/^[A-Za-z0-9]{44}$/', 'yubicoOtp')],
            ...self::authenticationResponseRules('secondFactor.credential', ['exclude_unless:secondFactor.type,webauthn']),
        ];
    }

    public function newPassword(): string
    {
        return (string) $this->input('newPassword');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function secondFactor(): ?array
    {
        $proof = $this->input('secondFactor');

        return is_array($proof) ? $proof : null;
    }
}
