<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\TrimsName;
use App\Http\Requests\Api\V2\Concerns\WebAuthnCredentialRules;
use App\Rules\MatchesFormat;
use App\Services\SecondFactors\SecondFactorEnrolmentService;

/**
 * Body of `enrolOwnSecondFactor` (`OwnSecondFactorEnrolmentRequest`, feature 005 FR-030–FR-033):
 * `type` selects the members; the name is trimmed and 1 to 255 characters long; the current
 * password is required unless a forced second-factor setup is pending.
 */
class EnrolOwnSecondFactorRequest extends ApiFormRequest
{
    use TrimsName;
    use WebAuthnCredentialRules;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $forced = app(SecondFactorEnrolmentService::class)->forcedSetupPending(RequestContext::requirePrincipal($this));
        $type = $this->input('type');

        $rules = [
            'type' => ['bail', 'required', 'string', 'in:totp,webauthn,yubiOtp'],
            'name' => ['bail', 'required', 'string', 'min:1', 'max:255'],
            'currentPassword' => $forced ? ['bail', 'nullable'] : ['bail', 'required', 'string', 'min:1', 'max:4096'],
        ];

        return match ($type) {
            'totp' => $rules + ['code' => ['bail', 'required', 'string', new MatchesFormat('/^[0-9]{6}$/', 'totpCode')]],
            'webauthn' => $rules + self::registrationResponseRules('credential'),
            'yubiOtp' => $rules + [
                'clientId' => ['bail', 'required', 'string', new MatchesFormat('/^[0-9]{1,32}$/', 'digits')],
                'secretKey' => ['bail', 'required', 'string', 'min:1', 'max:128'],
                'otp' => ['bail', 'required', 'string', new MatchesFormat('/^[A-Za-z0-9]{44}$/', 'yubicoOtp')],
            ],
            default => $rules,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function enrolment(): array
    {
        return $this->only(['type', 'name', 'code', 'credential', 'clientId', 'secretKey', 'otp', 'currentPassword']);
    }
}
