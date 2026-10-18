<?php

namespace App\Http\Requests\Api\V2;

use App\Http\Requests\Api\V2\Concerns\TrimsName;
use App\Http\Requests\Api\V2\Concerns\WebAuthnCredentialRules;

/**
 * Body of `registerPasskey` (contract `PasskeyRegistrationRequest`).
 */
class RegisterPasskeyRequest extends ApiFormRequest
{
    use TrimsName;
    use WebAuthnCredentialRules;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...self::registrationResponseRules('credential'),
            'name' => ['bail', 'sometimes', 'required', 'string', 'max:255'],
        ];
    }

    public function name(): ?string
    {
        $name = $this->validated('name');

        return is_string($name) ? $name : null;
    }
}
