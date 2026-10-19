<?php

namespace App\Http\Requests\Api\V2;

/**
 * Body of `createSessionWithSsoToken` (`SsoTokenSignInRequest`, feature 007 FR-060): `token`, a
 * string of 1 to 255 characters. Its format is checked by the sign-in, which answers every invalid
 * token with the generic sign-in error.
 */
class SsoTokenSignInRequest extends ApiFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'token' => ['bail', 'required', 'string', 'min:1', 'max:255'],
        ];
    }

    public function token(): string
    {
        return (string) $this->validated('token');
    }
}
