<?php

namespace App\Http\Requests\Api\V2\Settings;

use App\Rules\CharacterLength;
use App\Rules\GlobalSieveScript;
use App\Rules\QueryBoolean;
use App\Rules\StrictBoolean;
use App\Services\Settings\SettingsLimits;

/**
 * Body of `replaceGlobalSieveFilter` (`GlobalSieveFilterReplaceRequest`, feature 013 FR-063): the
 * script, at most 1 MiB after its line endings are normalised, valid for legacy's Sieve parser
 * unless empty or comments only, and `deferRestart`. `validateOnly=true` applies every rule.
 */
class ReplaceGlobalSieveFilterRequest extends SettingsRequest
{
    protected const MEMBERS = ['script', 'deferRestart'];

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'validateOnly' => ['bail', new QueryBoolean],
            'script' => ['bail', 'present', 'string', new CharacterLength(0, SettingsLimits::SIEVE_SCRIPT_MAX_BYTES, bytes: true), new GlobalSieveScript],
            'deferRestart' => ['bail', 'sometimes', new StrictBoolean],
        ];
    }

    /**
     * The script with normalised line endings.
     */
    public function script(): string
    {
        return GlobalSieveScript::normalise((string) $this->json('script'));
    }

    public function deferRestart(): bool
    {
        return $this->json('deferRestart') === true;
    }

    /**
     * The body with the script's line endings normalised, so that the size limit applies to the
     * script as it is stored.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $data = parent::validationData();

        if (is_string($data['script'] ?? null)) {
            $data['script'] = GlobalSieveScript::normalise($data['script']);
        }

        return $data;
    }
}
