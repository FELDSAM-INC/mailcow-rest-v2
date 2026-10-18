<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ListTlsPolicyMapsRequest;
use App\Http\Requests\Api\V2\StoreTlsPolicyMapRequest;
use App\Http\Requests\Api\V2\UpdateTlsPolicyMapRequest;
use App\Http\Resources\Api\V2\RoutingResource;
use App\Policies\RoutingPolicy;
use App\Services\Routing\TlsPolicyMapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * listTlsPolicyMaps, getTlsPolicyMap, createTlsPolicyMap, updateTlsPolicyMap and deleteTlsPolicyMap (feature 009 US6). Administrators only: the role is checked first (`403`), then the entry (`404`).
 */
class TlsPolicyMapController extends Controller
{
    public function __construct(
        private readonly TlsPolicyMapService $service,
        private readonly RoutingPolicy $policy,
    ) {}

    public function index(ListTlsPolicyMapsRequest $request): JsonResponse
    {
        return RoutingResource::list($this->service->page($request->listQuery(), $request->filters()));
    }

    public function show(Request $request, string $tlsPolicyMapId): JsonResponse
    {
        $this->policy->authorize(RequestContext::requirePrincipal($request), RoutingPolicy::MANAGE_ROUTING);

        return RoutingResource::response($this->found($tlsPolicyMapId));
    }

    public function store(StoreTlsPolicyMapRequest $request): JsonResponse
    {
        $id = $this->service->create(RequestContext::requirePrincipal($request), $request->members());

        return RoutingResource::response($this->found((string) $id), 201)->header('Location', '/api/v2/tls-policy-maps/'.$id);
    }

    public function update(UpdateTlsPolicyMapRequest $request): JsonResponse
    {
        $this->service->update(RequestContext::requirePrincipal($request), $request->entryId(), $request->members(), $request->header('If-Match'));

        return RoutingResource::response($this->found((string) $request->entryId()));
    }

    public function destroy(Request $request, string $tlsPolicyMapId): Response
    {
        $principal = RequestContext::requirePrincipal($request);
        $this->policy->authorize($principal, RoutingPolicy::MANAGE_ROUTING);
        $id = $this->service->resolve($tlsPolicyMapId) ?? throw new NotFoundHttpException('The TLS policy map does not exist.');

        $this->service->delete($principal, $id, $request->header('If-Match'));

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function found(string $tlsPolicyMapId): array
    {
        $id = $this->service->resolve($tlsPolicyMapId);
        $row = $id === null ? null : $this->service->find($id);

        return $row === null ? throw new NotFoundHttpException('The TLS policy map does not exist.') : TlsPolicyMapService::present($row);
    }
}
