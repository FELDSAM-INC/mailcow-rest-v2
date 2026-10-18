<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Policies\RoutingPolicy;
use App\Services\Routing\RelayhostQuery;
use App\Support\Http\ListQuery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Query of `listRelayhostUsage` (feature 009 FR-022): `kind` (`domain` or `mailbox`) and the shared
 * list parameters (`sort` of `RelayhostUsageSort`, default `kind`). Administrators only; an unknown
 * relayhost answers `404`.
 */
class ListRelayhostUsageRequest extends ApiFormRequest
{
    private int $relayhostId = 0;

    public function authorize(): bool
    {
        app(RoutingPolicy::class)->authorize(RequestContext::requirePrincipal($this), RoutingPolicy::MANAGE_ROUTING);
        $this->relayhostId = app(RelayhostQuery::class)->resolve((string) $this->route('relayhostId'))
            ?? throw new NotFoundHttpException('The relayhost does not exist.');

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['kind' => ['bail', 'string', 'in:domain,mailbox']];
    }

    public function relayhostId(): int
    {
        return $this->relayhostId;
    }

    public function listQuery(): ListQuery
    {
        return ListQuery::fromRequest($this, RelayhostQuery::USAGE_SORTS, 'kind');
    }

    public function kind(): ?string
    {
        $kind = $this->query('kind');

        return is_string($kind) ? $kind : null;
    }
}
