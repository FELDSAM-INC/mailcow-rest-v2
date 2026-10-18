<?php

namespace App\Http\Requests\Api\V2;

use App\Http\Requests\Api\V2\Concerns\ResolvesSelfServiceMailbox;
use App\Rules\StrictBoolean;

/**
 * Body of `updateMailboxMailSettings` (`MailboxMailSettingsPatch`, feature 005 FR-059): at least one
 * of the three settings; the flags are the service's (a value equal to the current one needs none).
 */
class UpdateMailboxMailSettingsRequest extends ApiFormRequest
{
    use ResolvesSelfServiceMailbox;

    protected bool $requiresNonEmptyBody = true;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'taggedMailHandling' => ['bail', 'sometimes', 'string', 'in:none,subfolder,subject'],
            'tlsEnforceInbound' => ['bail', 'sometimes', new StrictBoolean],
            'tlsEnforceOutbound' => ['bail', 'sometimes', new StrictBoolean],
        ];
    }

    /**
     * @return array{taggedMailHandling?: string, tlsEnforceInbound?: bool, tlsEnforceOutbound?: bool}
     */
    public function patch(): array
    {
        /** @var array{taggedMailHandling?: string, tlsEnforceInbound?: bool, tlsEnforceOutbound?: bool} */
        return $this->only(['taggedMailHandling', 'tlsEnforceInbound', 'tlsEnforceOutbound']);
    }
}
