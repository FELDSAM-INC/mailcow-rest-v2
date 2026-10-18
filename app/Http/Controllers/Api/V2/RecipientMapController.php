<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ListRecipientMapsRequest;
use App\Http\Requests\Api\V2\StoreRecipientMapRequest;
use App\Http\Requests\Api\V2\UpdateRecipientMapRequest;
use App\Http\Resources\Api\V2\RoutingResource;
use App\Policies\RoutingPolicy;
use App\Services\Routing\RecipientMapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * listRecipientMaps, getRecipientMap, createRecipientMap, updateRecipientMap and deleteRecipientMap (feature 009 US7). Administrators only: the role is checked first (`403`), then the entry (`404`).
 */
class RecipientMapController extends Controller
{
    public function __construct(
        private readonly RecipientMapService $service,
        private readonly RoutingPolicy $policy,
    ) {}

    public function index(ListRecipientMapsRequest $request): JsonResponse
    {
        return RoutingResource::list($this->service->page($request->listQuery(), $request->filters()));
    }

    public function show(Request $request, string $recipientMapId): JsonResponse
    {
        $this->policy->authorize(RequestContext::requirePrincipal($request), RoutingPolicy::MANAGE_ROUTING);

        return RoutingResource::response($this->found($recipientMapId));
    }

    public function store(StoreRecipientMapRequest $request): JsonResponse
    {
        $id = $this->service->create(RequestContext::requirePrincipal($request), $request->members());

        return RoutingResource::response($this->found((string) $id), 201)->header('Location', '/api/v2/recipient-maps/'.$id);
    }

    public function update(UpdateRecipientMapRequest $request): JsonResponse
    {
        $this->service->update(RequestContext::requirePrincipal($request), $request->entryId(), $request->members(), $request->header('If-Match'));

        return RoutingResource::response($this->found((string) $request->entryId()));
    }

    public function destroy(Request $request, string $recipientMapId): Response
    {
        $principal = RequestContext::requirePrincipal($request);
        $this->policy->authorize($principal, RoutingPolicy::MANAGE_ROUTING);
        $id = $this->service->resolve($recipientMapId) ?? throw new NotFoundHttpException('The recipient map does not exist.');

        $this->service->delete($principal, $id, $request->header('If-Match'));

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function found(string $recipientMapId): array
    {
        $id = $this->service->resolve($recipientMapId);
        $row = $id === null ? null : $this->service->find($id);

        return $row === null ? throw new NotFoundHttpException('The recipient map does not exist.') : RecipientMapService::present($row);
    }
}
