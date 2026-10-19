<?php

namespace App\Http\Requests\Api\V2\Settings;

/**
 * Body of `updateLegacyApiCorsSettings` (`LegacyApiCorsSettingsPatch`, feature 013 FR-068 to
 * FR-070): at most 100 origins of at most 2,048 characters, methods taken from GET, POST, PUT and
 * DELETE. The origins' format and the non-empty methods are checked by LegacyApiCorsService on the
 * merged settings, so that a stored invalid origin is reported too.
 */
class UpdateLegacyApiCorsRequest extends SettingsRequest
{
    protected const MEMBERS = ['allowedOrigins', 'allowedMethods'];

    protected bool $requiresNonEmptyBody = true;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'allowedOrigins' => ['bail', 'sometimes', 'list', 'max:100'],
            'allowedOrigins.*' => ['bail', 'string', 'max:2048'],
            'allowedMethods' => ['bail', 'sometimes', 'list', 'max:4'],
            'allowedMethods.*' => ['bail', 'string', 'in:GET,POST,PUT,DELETE'],
        ];
    }
}
