<?php

namespace App\Http\Requests\Api\V2;

use App\Http\Requests\Api\V2\Concerns\TrimsName;

/**
 * Body of `updatePasskey` (contract `PasskeyPatch`, JSON Merge Patch): the display name.
 */
class UpdatePasskeyRequest extends ApiFormRequest
{
    use TrimsName;

    protected bool $requiresNonEmptyBody = true;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'max:255'],
        ];
    }
}
