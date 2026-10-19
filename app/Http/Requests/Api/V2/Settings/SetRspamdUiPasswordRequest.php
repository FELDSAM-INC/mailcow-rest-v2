<?php

namespace App\Http\Requests\Api\V2\Settings;

use App\Rules\SingleLineText;

/**
 * Body of `setRspamdUiPassword` (`RspamdUiPasswordRequest`, feature 013 FR-066, research R16): the
 * password, 6 to 256 characters without control characters, kept exactly (quotes and spaces).
 * Never flashed (`password` is in the exception handler's `dontFlash` list), logged or echoed.
 */
class SetRspamdUiPasswordRequest extends SettingsRequest
{
    protected const MEMBERS = ['password'];

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'password' => ['bail', 'required', 'string', 'min:6', 'max:256', new SingleLineText],
        ];
    }

    public function password(): string
    {
        return (string) $this->json('password');
    }
}
