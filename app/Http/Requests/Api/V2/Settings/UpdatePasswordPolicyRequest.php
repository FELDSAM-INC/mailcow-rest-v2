<?php

namespace App\Http\Requests\Api\V2\Settings;

use App\Rules\StrictBoolean;
use App\Rules\StrictInteger;
use App\Services\Settings\SettingsLimits;

/**
 * Body of `updatePasswordPolicy` (`PasswordPolicyPatch`, feature 013 FR-036): a minimum length from
 * 3 to 64 (`outOfRange` with `min` and `max`; legacy silently keeps the old value) and four
 * booleans. Administrators only.
 */
class UpdatePasswordPolicyRequest extends SettingsRequest
{
    protected const MEMBERS = ['minLength', 'requireLetters', 'requireDigits', 'requireSpecialCharacters', 'requireMixedCase'];

    protected bool $requiresNonEmptyBody = true;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $boolean = ['bail', 'sometimes', new StrictBoolean];

        return [
            'minLength' => ['bail', 'sometimes', new StrictInteger, 'integer', 'between:'.SettingsLimits::PASSWORD_POLICY_MIN_LENGTH.','.SettingsLimits::PASSWORD_POLICY_MAX_LENGTH],
            'requireLetters' => $boolean,
            'requireDigits' => $boolean,
            'requireSpecialCharacters' => $boolean,
            'requireMixedCase' => $boolean,
        ];
    }
}
