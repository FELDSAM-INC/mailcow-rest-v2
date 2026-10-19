<?php

namespace App\Http\Requests\Api\V2\Settings;

use App\Auth\Principal;
use App\Policies\SettingsPolicy;
use App\Rules\BrandColorContrast;
use App\Rules\HexColor;
use App\Support\Color\WcagContrast;

/**
 * Body of `updateBrandColors` (`BrandColorsPatch`, feature 013 FR-029, FR-030): each colour `#RRGGBB`
 * in either case, or null to clear it; a light colour needs 4.5:1 against `#ffffff`, a dark colour
 * against `#1d2939` (`insufficientContrast`).
 */
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
            'light' => ['bail', 'nullable', 'string', new HexColor, new BrandColorContrast(WcagContrast::LIGHT_SURFACE)],
            'dark' => ['bail', 'nullable', 'string', new HexColor, new BrandColorContrast(WcagContrast::DARK_SURFACE)],
        ];
    }
}
