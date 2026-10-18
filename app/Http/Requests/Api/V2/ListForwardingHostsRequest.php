<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Policies\RoutingPolicy;
use App\Rules\QueryBoolean;
use App\Services\Routing\ForwardingHostService;
use App\Support\Http\ListQuery;

/**
 * Query of `listForwardingHosts` (feature 009 FR-074): `spamFilter`, `source` (exact; empty matches
 * entries without a source) and the shared list parameters (`sort` of `ForwardingHostSort`, default
 * `address`). Administrators only.
 */
class ListForwardingHostsRequest extends ApiFormRequest
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
            'spamFilter' => ['bail', new QueryBoolean],
            'source' => ['bail', 'nullable', 'string', 'max:255'],
        ];
    }

    public function listQuery(): ListQuery
    {
        return ListQuery::fromRequest($this, ForwardingHostService::SORTS, 'address');
    }

    /**
     * @return array{spamFilter: bool|null, source: string|null}
     */
    public function filters(): array
    {
        $query = $this->query();

        return [
            'spamFilter' => match ($this->query('spamFilter')) {
                'true' => true,
                'false' => false,
                default => null,
            },
            'source' => array_key_exists('source', $query) ? (string) ($query['source'] ?? '') : null,
        ];
    }
}
