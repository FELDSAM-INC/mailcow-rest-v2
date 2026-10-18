<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\ReadsRepeatedQuery;
use App\Policies\RoutingPolicy;
use App\Rules\QueryBoolean;
use App\Services\Routing\TlsPolicyMapService;
use App\Support\Http\ListQuery;
use App\Support\Routing\MapDestination;

/**
 * Query of `listTlsPolicyMaps` (feature 009 FR-065): the repeatable `policy`, `active`, and the
 * shared list parameters (`sort` of `TlsPolicyMapSort`, default `id`). Administrators only.
 */
class ListTlsPolicyMapsRequest extends ApiFormRequest
{
    use ReadsRepeatedQuery;

    public function authorize(): bool
    {
        app(RoutingPolicy::class)->authorize(RequestContext::requirePrincipal($this), RoutingPolicy::MANAGE_ROUTING);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $data = $this->query();
        unset($data['policy']);
        $policies = $this->repeatedQuery('policy');

        return $policies === [] ? $data : $data + ['policy' => $policies];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'policy' => ['bail', 'array', 'max:8'],
            'policy.*' => ['bail', 'string', 'in:'.implode(',', MapDestination::TLS_POLICIES)],
            'active' => ['bail', new QueryBoolean],
        ];
    }

    public function listQuery(): ListQuery
    {
        return ListQuery::fromRequest($this, TlsPolicyMapService::SORTS, 'id');
    }

    /**
     * @return array{policy: list<string>, active: bool|null}
     */
    public function filters(): array
    {
        return [
            'policy' => $this->repeatedQuery('policy'),
            'active' => match ($this->query('active')) {
                'true' => true,
                'false' => false,
                default => null,
            },
        ];
    }
}
