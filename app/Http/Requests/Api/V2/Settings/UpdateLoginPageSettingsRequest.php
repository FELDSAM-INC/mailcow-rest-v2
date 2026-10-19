<?php

namespace App\Http\Requests\Api\V2\Settings;

use App\Auth\Principal;
use App\Policies\SettingsPolicy;
use App\Rules\StrictBoolean;

/**
 * Body of `updateLoginPageSettings` (`LoginPageSettingsPatch`, feature 013 FR-024): four booleans.
 */
class UpdateLoginPageSettingsRequest extends SettingsRequest
{
    protected const MEMBERS = ['hideAdminLogin', 'hideDomainAdminLogin', 'hideMailboxUserLogin', 'forceSingleSignOn'];

    protected bool $requiresNonEmptyBody = true;

    protected function authorizeCaller(Principal $principal, SettingsPolicy $policy): void
    {
        $policy->requireCustomisation($principal);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_fill_keys(self::MEMBERS, ['bail', 'sometimes', new StrictBoolean]);
    }
}
