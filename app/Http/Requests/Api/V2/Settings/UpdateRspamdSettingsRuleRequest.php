<?php

namespace App\Http\Requests\Api\V2\Settings;

use App\Rules\CharacterLength;
use App\Rules\QueryBoolean;
use App\Rules\StrictBoolean;
use App\Rules\UclStructure;
use App\Services\Settings\SettingsLimits;

/**
 * Body of `updateRspamdSettingsRule` (`RspamdSettingsRulePatch`, feature 013 FR-053): the rules of
 * the creation for the sent members; an empty description or content is refused (legacy keeps the
 * old value). `validateOnly=true` applies every rule.
 */
class UpdateRspamdSettingsRuleRequest extends SettingsRequest
{
    protected const MEMBERS = ['description', 'content', 'active'];

    protected bool $requiresNonEmptyBody = true;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'validateOnly' => ['bail', new QueryBoolean],
            'description' => ['bail', 'sometimes', 'required', 'string', 'max:'.SettingsLimits::SINGLE_LINE_TEXT_MAX_LENGTH],
            'content' => ['bail', 'sometimes', 'required', 'string', new CharacterLength(1, SettingsLimits::RSPAMD_RULE_CONTENT_MAX_BYTES, bytes: true), new UclStructure],
            'active' => ['bail', 'sometimes', new StrictBoolean],
        ];
    }
}
