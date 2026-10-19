<?php

namespace App\Http\Requests\Api\V2\Settings;

use App\Rules\CharacterLength;
use App\Rules\QueryBoolean;
use App\Rules\StrictBoolean;
use App\Rules\UclStructure;
use App\Services\Settings\SettingsLimits;

/**
 * Body of `createRspamdSettingsRule` (`RspamdSettingsRuleCreateRequest`, feature 013 FR-051): a
 * description of 1 to 255 characters, content that is not empty after trimming, at most 1 MiB and
 * structurally balanced, and the optional active state. `validateOnly=true` applies every rule.
 */
class StoreRspamdSettingsRuleRequest extends SettingsRequest
{
    protected const MEMBERS = ['description', 'content', 'active'];

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'validateOnly' => ['bail', new QueryBoolean],
            'description' => ['bail', 'required', 'string', 'max:'.SettingsLimits::SINGLE_LINE_TEXT_MAX_LENGTH],
            'content' => ['bail', 'required', 'string', new CharacterLength(1, SettingsLimits::RSPAMD_RULE_CONTENT_MAX_BYTES, bytes: true), new UclStructure],
            'active' => ['bail', 'sometimes', new StrictBoolean],
        ];
    }

    /**
     * @return array{description: string, content: string, active?: bool}
     */
    public function values(): array
    {
        /** @var array{description: string, content: string, active?: bool} $values */
        $values = $this->patch();

        return $values;
    }
}
