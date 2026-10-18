<?php

namespace App\Http\Requests\Api\V2;

use App\Auth\Role;
use App\Http\Requests\Api\V2\Concerns\WebAuthnCredentialRules;

/**
 * Body of `createSessionWithPasskey` (contract `PasskeySignInRequest`).
 */
class PasskeySignInRequest extends ApiFormRequest
{
    use WebAuthnCredentialRules;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...self::authenticationResponseRules('credential'),
            'role' => ['bail', 'sometimes', 'string', 'in:admin,domainAdmin,mailboxUser'],
        ];
    }

    public function role(): ?Role
    {
        $role = $this->validated('role');

        return is_string($role) ? Role::from($role) : null;
    }
}
