<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Policies\RoutingPolicy;
use App\Services\Routing\RelayhostQuery;
use App\Support\Http\ListQuery;

/**
 * Query of `listRelayhostChoices` (feature 009 FR-023): the shared list parameters (`sort` of
 * `RelayhostChoiceSort`, default `id` ascending). Administrators, and domain administrators with
 * the `domainRelayhost` or the `mailboxRelayhost` flag.
 */
class ListRelayhostChoicesRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        app(RoutingPolicy::class)->authorize(RequestContext::requirePrincipal($this), RoutingPolicy::VIEW_RELAYHOST_CHOICES);

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    public function listQuery(): ListQuery
    {
        return ListQuery::fromRequest($this, RelayhostQuery::CHOICE_SORTS, 'id');
    }
}
