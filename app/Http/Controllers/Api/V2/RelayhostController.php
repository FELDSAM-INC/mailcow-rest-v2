<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ListRelayhostsRequest;
use App\Http\Requests\Api\V2\StoreRelayhostRequest;
use App\Http\Requests\Api\V2\UpdateRelayhostRequest;
use App\Http\Resources\Api\V2\RoutingResource;
use App\Policies\RoutingPolicy;
use App\Services\Routing\RelayhostQuery;
use App\Services\Routing\RelayhostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * listRelayhosts, getRelayhost, createRelayhost, updateRelayhost and deleteRelayhost (feature 009
 * US1). Administrators only: the role is checked first (`403`), then the relayhost (`404`), then
 * the request.
 */
class RelayhostController extends Controller
{
    public function __construct(
        private readonly RelayhostQuery $query,
        private readonly RoutingPolicy $policy,
    ) {}

    public function index(ListRelayhostsRequest $request): JsonResponse
    {
        return RoutingResource::list($this->query->page($request->listQuery(), $request->filters()));
    }

    public function show(Request $request, string $relayhostId): JsonResponse
    {
        $this->policy->authorize(RequestContext::requirePrincipal($request), RoutingPolicy::MANAGE_ROUTING);

        return RoutingResource::response($this->found($relayhostId));
    }

    public function store(StoreRelayhostRequest $request, RelayhostService $service): JsonResponse
    {
        $id = $service->create(RequestContext::requirePrincipal($request), $request->members());

        return RoutingResource::response($this->found((string) $id), 201)->header('Location', '/api/v2/relayhosts/'.$id);
    }

    public function update(UpdateRelayhostRequest $request, RelayhostService $service): JsonResponse
    {
        $service->update(RequestContext::requirePrincipal($request), $request->relayhostId(), $request->members(), $request->header('If-Match'));

        return RoutingResource::response($this->found((string) $request->relayhostId()));
    }

    public function destroy(Request $request, string $relayhostId, RelayhostService $service): Response
    {
        $principal = RequestContext::requirePrincipal($request);
        $this->policy->authorize($principal, RoutingPolicy::MANAGE_ROUTING);
        $id = $this->query->resolve($relayhostId) ?? throw new NotFoundHttpException('The relayhost does not exist.');

        $service->delete($principal, $id, $request->header('If-Match'));

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function found(string $relayhostId): array
    {
        $id = $this->query->resolve($relayhostId);
        $row = $id === null ? null : $this->query->find($id);

        return $row === null ? throw new NotFoundHttpException('The relayhost does not exist.') : RelayhostQuery::present($row);
    }
}
