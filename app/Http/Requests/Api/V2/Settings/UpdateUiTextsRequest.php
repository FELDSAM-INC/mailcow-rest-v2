<?php

namespace App\Http\Requests\Api\V2\Settings;

use App\Auth\Principal;
use App\Policies\SettingsPolicy;
use App\Rules\SingleLineText;
use App\Rules\StrictBoolean;
use App\Services\Settings\SettingsLimits;

/**
 * Body of `updateUiTexts` (`UiTextsPatch`, feature 013 FR-019 to FR-022): the single-line texts at
 * most 255 characters without control characters, the HTML texts and the announcement text at most
 * 65,535 characters, the announcement type `info`, `warning`, `danger` or null.
 */
class UpdateUiTextsRequest extends SettingsRequest
{
    protected const MEMBERS = ['pageTitle', 'productName', 'appsHeading', 'loginHelpHtml', 'footerHtml', 'announcement'];

    protected const NESTED = ['announcement' => ['text', 'type', 'active']];

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
        $single = ['bail', 'sometimes', 'string', 'max:'.SettingsLimits::SINGLE_LINE_TEXT_MAX_LENGTH, new SingleLineText];
        $html = ['bail', 'sometimes', 'string', 'max:'.SettingsLimits::HTML_TEXT_MAX_LENGTH];

        return [
            'pageTitle' => $single,
            'productName' => $single,
            'appsHeading' => $single,
            'loginHelpHtml' => $html,
            'footerHtml' => $html,
            'announcement' => ['bail', 'sometimes', 'array', 'min:1'],
            'announcement.text' => $html,
            'announcement.type' => ['bail', 'nullable', 'string', 'in:info,warning,danger'],
            'announcement.active' => ['bail', 'sometimes', new StrictBoolean],
        ];
    }
}
