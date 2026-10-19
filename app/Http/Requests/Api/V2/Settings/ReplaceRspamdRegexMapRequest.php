<?php

namespace App\Http\Requests\Api\V2\Settings;

use App\Rules\CharacterLength;
use App\Rules\QueryBoolean;
use App\Rules\RegexMapLines;
use App\Services\Settings\SettingsLimits;

/**
 * Body of `replaceRspamdRegexMap` (`RspamdRegexMapReplaceRequest`, feature 013 FR-057): the content,
 * at most 1 MiB, every line valid. `validateOnly=true` applies every rule.
 */
class ReplaceRspamdRegexMapRequest extends SettingsRequest
{
    protected const MEMBERS = ['content'];

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'validateOnly' => ['bail', new QueryBoolean],
            'content' => ['bail', 'present', 'string', new CharacterLength(0, SettingsLimits::RSPAMD_REGEX_MAP_MAX_BYTES, bytes: true), new RegexMapLines],
        ];
    }

    public function content(): string
    {
        return (string) $this->json('content');
    }
}
