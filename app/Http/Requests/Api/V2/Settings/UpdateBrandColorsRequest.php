<?php

namespace App\Http\Requests\Api\V2\Settings;

use App\Auth\Principal;
use App\Policies\SettingsPolicy;
use App\Rules\HexColor;

/** Any six-digit sRGB accent, or null to clear it. Foreground text is chosen separately. */
class UpdateBrandColorsRequest extends SettingsRequest
{
    protected const MEMBERS = ['light', 'dark'];

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
        return [
            'light' => ['bail', 'nullable', 'string', new HexColor],
            'dark' => ['bail', 'nullable', 'string', new HexColor],
        ];
    }
}
