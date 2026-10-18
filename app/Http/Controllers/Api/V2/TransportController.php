<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ListTransportsRequest;
use App\Http\Requests\Api\V2\StoreTransportRequest;
use App\Http\Requests\Api\V2\UpdateTransportRequest;
use App\Http\Resources\Api\V2\RoutingResource;
use App\Policies\RoutingPolicy;
use App\Services\Routing\TransportQuery;
use App\Services\Routing\TransportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * listTransports, getTransport, createTransport, updateTransport and deleteTransport (feature 009
 * US3). Creations and edits answer `{data, meta.affectedTransports}`: the other transports whose
 * stored credentials the request changed (plan Complexity Tracking).
 */
class TransportController extends Controller
{
    public function __construct(
        private readonly TransportQuery $query,
        private readonly RoutingPolicy $policy,
    ) {}

    public function index(ListTransportsRequest $request): JsonResponse
    {
        return RoutingResource::list($this->query->page($request->listQuery(), $request->filters()));
    }

    public function show(Request $request, string $transportId): JsonResponse
    {
        $this->policy->authorize(RequestContext::requirePrincipal($request), RoutingPolicy::MANAGE_ROUTING);

        return RoutingResource::response($this->found($transportId));
    }

    public function store(StoreTransportRequest $request, TransportService $service): JsonResponse
    {
        $result = $service->create(RequestContext::requirePrincipal($request), $request->members());

        return RoutingResource::response($this->found((string) $result['id']), 201, ['affectedTransports' => $service->affectedReferences($result['affected'])])
            ->header('Location', '/api/v2/transports/'.$result['id']);
    }

    public function update(UpdateTransportRequest $request, TransportService $service): JsonResponse
    {
        $affected = $service->update(RequestContext::requirePrincipal($request), $request->transportId(), $request->members(), $request->header('If-Match'));

        return RoutingResource::response($this->found((string) $request->transportId()), 200, ['affectedTransports' => $service->affectedReferences($affected)]);
    }

    public function destroy(Request $request, string $transportId, TransportService $service): Response
    {
        $principal = RequestContext::requirePrincipal($request);
        $this->policy->authorize($principal, RoutingPolicy::MANAGE_ROUTING);
        $id = $this->query->resolve($transportId) ?? throw new NotFoundHttpException('The transport does not exist.');

        $service->delete($principal, $id, $request->header('If-Match'));

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function found(string $transportId): array
    {
        $id = $this->query->resolve($transportId);
        $row = $id === null ? null : $this->query->find($id);

        return $row === null ? throw new NotFoundHttpException('The transport does not exist.') : TransportQuery::present($row);
    }
}
