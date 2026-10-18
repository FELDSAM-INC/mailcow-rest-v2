<?php

namespace App\Http\Requests\Api\V2;

use App\Rules\EnabledLanguage;

/**
 * Body of `updatePreferences` (contract `PreferencesPatch`, JSON Merge Patch): at least one member;
 * `language` one of the enabled languages, `theme` light, dark or system. `null` is refused
 * (`invalidType`): resetting to the defaults is not a feature.
 */
class UpdatePreferencesRequest extends ApiFormRequest
{
    protected bool $requiresNonEmptyBody = true;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'language' => ['bail', 'sometimes', 'string', 'max:16', app(EnabledLanguage::class)],
            'theme' => ['bail', 'sometimes', 'string', 'in:light,dark,system'],
        ];
    }

    /**
     * The validated changes.
     *
     * @return array<string, string>
     */
    public function changes(): array
    {
        return array_map('strval', array_intersect_key($this->validated(), array_flip(['language', 'theme'])));
    }
}
