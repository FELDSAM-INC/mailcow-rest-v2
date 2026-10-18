<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Policies\RoutingPolicy;
use App\Rules\QueryBoolean;
use App\Services\Routing\TransportQuery;
use App\Support\Http\ListQuery;
use App\Support\Routing\NextHop;

/**
 * Query of `listTransports` (feature 009 FR-031, FR-032, G35): the booleans `active` and `mxBased`,
 * `nextHop` (exact, letter case ignored, normalised like a stored next hop: a bare IP address is
 * bracketed, a Unicode host converted to ASCII), and the shared list parameters (`sort` of
 * `TransportSort`, default `id` ascending). Administrators only.
 */
class ListTransportsRequest extends ApiFormRequest
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
            'mxBased' => ['bail', new QueryBoolean],
            'nextHop' => ['bail', 'string', 'max:255'],
        ];
    }

    public function listQuery(): ListQuery
    {
        return ListQuery::fromRequest($this, TransportQuery::SORTS, 'id');
    }

    /**
     * @return array{active: bool|null, mxBased: bool|null, nextHop: string|null}
     */
    public function filters(): array
    {
        $bool = fn (string $name): ?bool => match ($this->query($name)) {
            'true' => true,
            'false' => false,
            default => null,
        };
        $nextHop = $this->query('nextHop');

        return [
            'active' => $bool('active'),
            'mxBased' => $bool('mxBased'),
            'nextHop' => is_string($nextHop) ? (NextHop::tryParse($nextHop)?->stored() ?? trim($nextHop)) : null,
        ];
    }
}
