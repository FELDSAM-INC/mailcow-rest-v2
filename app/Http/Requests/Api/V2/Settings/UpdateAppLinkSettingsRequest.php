<?php

namespace App\Http\Requests\Api\V2\Settings;

use App\Auth\Principal;
use App\Policies\SettingsPolicy;
use App\Rules\AppLinkUrl;
use App\Rules\SingleLineText;
use App\Rules\StrictBoolean;
use App\Services\Settings\SettingsLimits;
use Illuminate\Contracts\Validation\Validator;

/**
 * Body of `updateAppLinkSettings` (`AppLinkSettingsPatch`, feature 013 FR-025, FR-026): the whole list
 * of custom links, at most 50; each entry's name 1 to 255 characters on one line, its addresses
 * absolute `http`/`https` URLs or paths starting with a single `/`. Every invalid entry is named by
 * its index (`links.3.url`), and nothing is stored.
 */
class UpdateAppLinkSettingsRequest extends SettingsRequest
{
    protected const MEMBERS = ['links'];

    private const ENTRY_MEMBERS = ['name', 'url', 'signedInUrl', 'showOnLoginPage'];

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
            'links' => ['bail', 'present', 'array', 'list', 'max:'.SettingsLimits::APP_LINKS_MAX],
            'links.*' => ['bail', 'array'],
            'links.*.name' => ['bail', 'required', 'string', 'max:'.SettingsLimits::SINGLE_LINE_TEXT_MAX_LENGTH, new SingleLineText],
            'links.*.url' => ['bail', 'required', 'string', new AppLinkUrl],
            'links.*.signedInUrl' => ['bail', 'nullable', 'string', new AppLinkUrl],
            'links.*.showOnLoginPage' => ['bail', 'required', new StrictBoolean],
        ];
    }

    protected function additionalViolations(Validator $validator): array
    {
        $violations = [];
        $links = $this->json('links');

        foreach (is_array($links) && array_is_list($links) ? $links : [] as $i => $link) {
            foreach (is_array($link) ? array_keys($link) : [] as $member) {
                if (! in_array($member, self::ENTRY_MEMBERS, true)) {
                    $violations["links.{$i}.{$member}"][] = self::make('unknownField', [], 'This member is not accepted here.');
                }
            }
        }

        return $violations;
    }

    /**
     * @return list<array{name: string, url: string, signedInUrl: string|null, showOnLoginPage: bool}>
     */
    public function links(): array
    {
        $links = [];

        foreach ((array) $this->json('links') as $link) {
            $links[] = [
                'name' => (string) $link['name'],
                'url' => (string) $link['url'],
                'signedInUrl' => isset($link['signedInUrl']) ? (string) $link['signedInUrl'] : null,
                'showOnLoginPage' => (bool) $link['showOnLoginPage'],
            ];
        }

        return $links;
    }
}
