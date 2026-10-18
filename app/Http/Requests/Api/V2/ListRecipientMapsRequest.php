<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Policies\RoutingPolicy;
use App\Rules\QueryBoolean;
use App\Services\Routing\RecipientMapService;
use App\Support\Http\ListQuery;

/**
 * Query of `listRecipientMaps` (feature 009 FR-059): `active` and the shared list parameters
 * (`sort` of `RecipientMapSort`, default `id`). Administrators only.
 */
class ListRecipientMapsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        app(RoutingPolicy::class)->authorize(RequestContext::requirePrincipal($this), RoutingPolicy::MANAGE_ROUTING);

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['active' => ['bail', new QueryBoolean]];
    }

    public function listQuery(): ListQuery
    {
        return ListQuery::fromRequest($this, RecipientMapService::SORTS, 'id');
    }

    /**
     * @return array{active: bool|null}
     */
    public function filters(): array
    {
        return ['active' => match ($this->query('active')) {
            'true' => true,
            'false' => false,
            default => null,
        }];
    }
}
