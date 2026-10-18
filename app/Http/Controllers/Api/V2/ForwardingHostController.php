<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ListForwardingHostsRequest;
use App\Http\Requests\Api\V2\StoreForwardingHostRequest;
use App\Http\Requests\Api\V2\UpdateForwardingHostRequest;
use App\Http\Resources\Api\V2\RoutingResource;
use App\Policies\RoutingPolicy;
use App\Services\Routing\ForwardingHostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * listForwardingHosts, getForwardingHost, createForwardingHost, updateForwardingHost and
 * deleteForwardingHost (feature 009 US8). An entry is addressed by its stored address,
 * percent-encoded in the path. A creation answers `201` with the list filtered by its source when
 * it added an address, otherwise `200` with the updated entries.
 */
class ForwardingHostController extends Controller
{
    public function __construct(
        private readonly ForwardingHostService $service,
        private readonly RoutingPolicy $policy,
    ) {}

    public function index(ListForwardingHostsRequest $request): JsonResponse
    {
        return RoutingResource::list($this->service->page($request->listQuery(), $request->filters()));
    }

    public function show(Request $request, string $forwardingHostAddress): JsonResponse
    {
        $this->policy->authorize(RequestContext::requirePrincipal($request), RoutingPolicy::MANAGE_ROUTING);

        return RoutingResource::response($this->service->find($forwardingHostAddress) ?? throw new NotFoundHttpException('The forwarding host does not exist.'));
    }

    public function store(StoreForwardingHostRequest $request): JsonResponse
    {
        $outcome = $this->service->create(RequestContext::requirePrincipal($request), $request->host(), $request->spamFilter());
        $response = response()->json(['data' => $outcome['result']], $outcome['created'] ? 201 : 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $outcome['created'] ? $response->header('Location', '/api/v2/forwarding-hosts?source='.rawurlencode($outcome['result']['source'])) : $response;
    }

    public function update(UpdateForwardingHostRequest $request, string $forwardingHostAddress): JsonResponse
    {
        $this->service->update(RequestContext::requirePrincipal($request), $forwardingHostAddress, $request->members(), $request->header('If-Match'));

        return RoutingResource::response($this->service->find($forwardingHostAddress) ?? throw new NotFoundHttpException('The forwarding host does not exist.'));
    }

    public function destroy(Request $request, string $forwardingHostAddress): Response
    {
        $principal = RequestContext::requirePrincipal($request);
        $this->policy->authorize($principal, RoutingPolicy::MANAGE_ROUTING);
        $this->service->delete($principal, $forwardingHostAddress, $request->header('If-Match'));

        return response()->noContent();
    }
}
