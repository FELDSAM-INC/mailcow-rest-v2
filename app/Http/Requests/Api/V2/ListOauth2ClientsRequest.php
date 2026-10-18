<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Models\Mailcow\Oauth2Client;
use App\Rules\QueryBoolean;
use App\Services\Oauth2\Oauth2ClientService;
use App\Support\Http\ListQuery;
use Illuminate\Support\Facades\Gate;

/**
 * Query of `listOauth2Clients` (FR-041): the shared list parameters (`sort` id, clientId or
 * redirectUri, default id ascending; `q` on the client ID and the redirect URIs) and
 * `hasActiveTokens`. Administrators only.
 */
class ListOauth2ClientsRequest extends ApiFormRequest
{
    private ?ListQuery $listQuery = null;

    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows('viewAny', Oauth2Client::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['hasActiveTokens' => ['bail', new QueryBoolean]];
    }

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->query();
    }

    public function listQuery(): ListQuery
    {
        return $this->listQuery ??= ListQuery::fromRequest($this, Oauth2ClientService::SORTS, 'id');
    }

    public function hasActiveTokens(): ?bool
    {
        return match ($this->query('hasActiveTokens')) {
            'true' => true,
            'false' => false,
            default => null,
        };
    }
}
