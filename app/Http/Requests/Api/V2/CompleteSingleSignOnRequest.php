<?php

namespace App\Http\Requests\Api\V2;

use App\Rules\PrecomputedViolation;

/**
 * Body of `completeSingleSignOn` (`SingleSignOnCompletionRequest`): the `code` and `state` the
 * provider returned, unchanged, and nothing else.
 */
class CompleteSingleSignOnRequest extends ApiFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'code' => ['bail', 'required', 'string', 'max:4096'],
            'state' => ['bail', 'required', 'string', 'max:512'],
        ];

        foreach (array_diff(array_keys((array) $this->all()), array_keys($rules)) as $member) {
            $rules[(string) $member] = [new PrecomputedViolation(ApiFormRequest::make('unknownField', [], 'This member is not accepted.'))];
        }

        return $rules;
    }
}
