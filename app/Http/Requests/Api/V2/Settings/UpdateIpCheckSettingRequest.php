<?php

namespace App\Http\Requests\Api\V2\Settings;

use App\Auth\Principal;
use App\Policies\SettingsPolicy;
use App\Rules\StrictBoolean;

/**
 * Body of `updateIpCheckSetting` (`IpCheckSettingPatch`, feature 013 FR-035): `enabled`, a boolean.
 * A customisation write, refused in demo mode (FR-012).
 */
class UpdateIpCheckSettingRequest extends SettingsRequest
{
    protected const MEMBERS = ['enabled'];

    protected function authorizeCaller(Principal $principal, SettingsPolicy $policy): void
    {
        $policy->requireCustomisation($principal);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['bail', 'required', new StrictBoolean],
        ];
    }

    public function enabled(): bool
    {
        return $this->json('enabled') === true;
    }
}
