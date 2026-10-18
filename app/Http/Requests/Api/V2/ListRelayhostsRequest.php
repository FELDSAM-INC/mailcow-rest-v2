<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Policies\RoutingPolicy;
use App\Rules\QueryBoolean;
use App\Services\Routing\RelayhostQuery;
use App\Support\Http\ListQuery;

/**
 * Query of `listRelayhosts` (feature 009 FR-015, FR-016): the booleans `active` and `inUse`, and the
 * shared list parameters (`sort` of `RelayhostSort`, default `id` ascending). Administrators only.
 */
class ListRelayhostsRequest extends ApiFormRequest
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
        return [
            'active' => ['bail', new QueryBoolean],
            'inUse' => ['bail', new QueryBoolean],
        ];
    }

    public function listQuery(): ListQuery
    {
        return ListQuery::fromRequest($this, RelayhostQuery::SORTS, 'id');
    }

    /**
     * @return array{active: bool|null, inUse: bool|null}
     */
    public function filters(): array
    {
        return ['active' => $this->booleanQuery('active'), 'inUse' => $this->booleanQuery('inUse')];
    }

    private function booleanQuery(string $name): ?bool
    {
        return match ($this->query($name)) {
            'true' => true,
            'false' => false,
            default => null,
        };
    }
}
