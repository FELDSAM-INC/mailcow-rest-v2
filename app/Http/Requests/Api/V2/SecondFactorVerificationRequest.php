<?php

namespace App\Http\Requests\Api\V2;

use App\Http\Requests\Api\V2\Concerns\WebAuthnCredentialRules;
use App\Rules\MatchesFormat;

/**
 * Body of `verifySecondFactor` (contract `SecondFactorVerification`): a TOTP code with its method,
 * a WebAuthn assertion or a Yubico OTP.
 */
class SecondFactorVerificationRequest extends ApiFormRequest
{
    use WebAuthnCredentialRules;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['bail', 'required', 'string', 'in:totp,webauthn,yubiOtp'],
            'methodId' => ['bail', 'exclude_unless:type,totp', 'required', 'integer', 'min:1'],
            'code' => ['bail', 'exclude_unless:type,totp', 'required', 'string', new MatchesFormat('/^[0-9]{6}$/', 'totpCode')],
            'otp' => ['bail', 'exclude_unless:type,yubiOtp', 'required', 'string', new MatchesFormat('/^[A-Za-z0-9]{44}$/', 'yubicoOtp')],
            ...self::authenticationResponseRules('credential', ['exclude_unless:type,webauthn']),
        ];
    }
}
