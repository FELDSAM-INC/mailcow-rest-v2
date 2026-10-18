<?php

namespace App\Http\Requests\Api\V2;

use App\Services\PasswordReset\PasswordResetThrottle;

/**
 * Body of `checkPasswordResetToken` and `createPasswordResetWebAuthnChallenge`: the token from the
 * reset link, in the body so that it never lands in an access log. Token checks are limited per
 * client address (FR-026).
 */
class CheckPasswordResetTokenRequest extends ApiFormRequest
{
    /** The throttle step this request counts on. */
    protected string $throttleStep = 'check';

    public function authorize(): bool
    {
        app(PasswordResetThrottle::class)->attempt($this->throttleStep, (string) $this->ip());

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['token' => ['bail', 'required', 'string', 'min:1', 'max:64']];
    }

    public function token(): string
    {
        return (string) $this->input('token');
    }
}
