<?php

namespace App\Http\Requests\Api\V2;

use App\Auth\Role;

/**
 * Body of `createSession` (contract `PasswordSignInRequest`).
 */
class PasswordSignInRequest extends ApiFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'username' => ['bail', 'required', 'string', 'max:255'],
            'password' => ['bail', 'required', 'string', 'max:4096'],
            'role' => ['bail', 'sometimes', 'string', 'in:admin,domainAdmin,mailboxUser'],
        ];
    }

    public function role(): ?Role
    {
        $role = $this->validated('role');

        return is_string($role) ? Role::from($role) : null;
    }
}
