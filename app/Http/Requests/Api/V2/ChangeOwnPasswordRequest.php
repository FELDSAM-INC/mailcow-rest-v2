<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Rules\StrictBoolean;
use App\Services\Account\OwnPasswordService;

/**
 * Body of `changeOwnPassword` (`OwnPasswordChangeRequest`): the new password once, the current
 * password unless a forced password change is pending (where it is ignored), and whether to end
 * the account's other sessions (default true). The password rules are OwnPasswordService's.
 */
class ChangeOwnPasswordRequest extends ApiFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $forced = app(OwnPasswordService::class)->resolvesForcedChange(RequestContext::requirePrincipal($this));

        return [
            'newPassword' => ['bail', 'required', 'string', 'min:1', 'max:4096'],
            'currentPassword' => $forced ? ['bail', 'nullable'] : ['bail', 'required', 'string', 'min:1', 'max:4096'],
            'endOtherSessions' => ['bail', 'sometimes', new StrictBoolean],
        ];
    }

    public function currentPassword(): ?string
    {
        $value = $this->input('currentPassword');

        return is_string($value) ? $value : null;
    }

    public function newPassword(): string
    {
        return (string) $this->input('newPassword');
    }

    public function endOtherSessions(): bool
    {
        return $this->input('endOtherSessions', true) !== false;
    }
}
