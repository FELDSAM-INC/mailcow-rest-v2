<?php

namespace App\Http\Requests\Api\V2\Concerns;

use App\Http\Requests\Api\V2\ApiFormRequest;
use App\Rules\PrecomputedViolation;
use App\Rules\RedirectUri;
use App\Services\Oauth2\Oauth2ClientService;

/**
 * The redirect URIs of an OAuth2 client (FR-044): a list of 1 or more absolute URIs with a scheme,
 * without a fragment and without whitespace, at most 2,000 characters when joined by single spaces
 * (duplicates counted once, as they are stored).
 */
trait RedirectUriRules
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function redirectUriRules(bool $required): array
    {
        $uris = $this->input('redirectUris');
        $tooLong = null;

        if (is_array($uris) && array_filter($uris, 'is_string') === $uris) {
            $joined = implode(' ', Oauth2ClientService::normaliseUris(array_values($uris)));

            if (mb_strlen($joined) > Oauth2ClientService::REDIRECT_URIS_MAX_LENGTH) {
                $tooLong = ApiFormRequest::make('tooLong', ['max' => Oauth2ClientService::REDIRECT_URIS_MAX_LENGTH], 'The redirect URIs joined by spaces may not exceed 2000 characters.');
            }
        }

        return [
            'redirectUris' => ['bail', $required ? 'required' : 'sometimes', 'array', 'list', 'min:1', new PrecomputedViolation($tooLong)],
            'redirectUris.*' => ['bail', 'string', 'max:2000', new RedirectUri],
        ];
    }

    /**
     * @return list<string>
     */
    public function redirectUris(): array
    {
        return array_values(array_map('strval', (array) $this->input('redirectUris', [])));
    }
}
