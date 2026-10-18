<?php

namespace App\Http\Requests\Api\V2;

/**
 * Body of `removeAllOwnSecondFactors` (`OwnSecondFactorRemovalRequest`): the current password.
 */
class RemoveAllOwnSecondFactorsRequest extends ApiFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['currentPassword' => ['bail', 'required', 'string', 'min:1', 'max:4096']];
    }

    public function currentPassword(): string
    {
        return (string) $this->input('currentPassword');
    }
}
