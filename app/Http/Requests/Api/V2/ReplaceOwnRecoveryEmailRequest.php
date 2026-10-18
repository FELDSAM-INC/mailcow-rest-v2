<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Rules\EmailAddress;
use App\Services\Account\RecoveryEmailService;

/**
 * Body of `replaceOwnRecoveryEmail` (`OwnRecoveryEmailReplacement`, feature 005 FR-018): `null` or
 * `""` removes the address; otherwise a valid e-mail address of at most 255 characters. The role and
 * the source are checked first (`403`).
 */
class ReplaceOwnRecoveryEmailRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        app(RecoveryEmailService::class)->assertEligible(RequestContext::requirePrincipal($this));

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'address' => ['bail', 'present', 'nullable', 'string', 'max:255', new EmailAddress(allowEmpty: true)],
            'currentPassword' => ['bail', 'required', 'string', 'min:1', 'max:4096'],
        ];
    }

    public function address(): string
    {
        $address = $this->input('address');

        return is_string($address) ? $address : '';
    }

    public function currentPassword(): string
    {
        return (string) $this->input('currentPassword');
    }
}
