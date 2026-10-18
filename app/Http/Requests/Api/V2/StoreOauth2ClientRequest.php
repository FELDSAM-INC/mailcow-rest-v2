<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\RedirectUriRules;
use App\Models\Mailcow\Oauth2Client;
use Illuminate\Support\Facades\Gate;

/**
 * Body of `createOauth2Client` (`Oauth2ClientCreateRequest`): the redirect URIs (FR-043, FR-044).
 * Administrators only.
 */
class StoreOauth2ClientRequest extends ApiFormRequest
{
    use RedirectUriRules;

    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows('create', Oauth2Client::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->redirectUriRules(true);
    }
}
