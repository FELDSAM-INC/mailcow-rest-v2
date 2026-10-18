<?php

namespace App\Http\Requests\Api\V2;

use App\Rules\MatchesFormat;
use App\Rules\PrecomputedViolation;

/**
 * Body of `reportSingleSignOnProviderError` (`SingleSignOnProviderErrorRequest`): the provider's
 * OAuth 2.0 error code (1 to 64 characters of RFC 6749's error character set) and the state. The
 * provider's free-text description is never accepted (FR-063).
 */
class ReportSingleSignOnProviderErrorRequest extends ApiFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'error' => ['bail', 'required', 'string', new MatchesFormat('/^[\x20-\x21\x23-\x5B\x5D-\x7E]{1,64}$/', 'oauthErrorCode')],
            'state' => ['bail', 'required', 'string', 'max:512'],
        ];

        foreach (array_diff(array_keys((array) $this->all()), array_keys($rules)) as $member) {
            $rules[(string) $member] = [new PrecomputedViolation(ApiFormRequest::make('unknownField', [], 'This member is not accepted.'))];
        }

        return $rules;
    }
}
