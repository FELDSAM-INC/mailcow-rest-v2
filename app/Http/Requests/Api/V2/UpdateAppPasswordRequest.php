<?php

namespace App\Http\Requests\Api\V2;

use App\Http\Requests\Api\V2\Concerns\ResolvesSelfServiceMailbox;
use App\Rules\ExcludesMember;
use App\Rules\StrictBoolean;
use App\Services\AppPasswords\AppPasswordService;

/**
 * Body of `updateAppPassword` (`AppPasswordPatch`, JSON Merge Patch, feature 005 FR-039): at least
 * one member; `password` and `generatePassword` exclude each other.
 */
class UpdateAppPasswordRequest extends ApiFormRequest
{
    use ResolvesSelfServiceMailbox;

    protected bool $requiresNonEmptyBody = true;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'sometimes', 'string', 'max:255'],
            'password' => ['bail', 'sometimes', 'string', 'min:1', 'max:4096', new ExcludesMember('generatePassword', true)],
            'generatePassword' => ['bail', 'sometimes', 'accepted', new StrictBoolean],
            'protocols' => ['bail', 'sometimes', 'array', 'list'],
            'protocols.*' => ['bail', 'string', 'distinct', 'in:'.implode(',', array_keys(AppPasswordService::PROTOCOLS))],
            'active' => ['bail', 'sometimes', new StrictBoolean],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function members(): array
    {
        return $this->only(['name', 'password', 'generatePassword', 'protocols', 'active']);
    }
}
