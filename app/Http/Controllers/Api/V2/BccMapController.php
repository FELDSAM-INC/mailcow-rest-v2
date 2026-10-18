<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ListBccDestinationChoicesRequest;
use App\Http\Requests\Api\V2\ListBccMapsRequest;
use App\Http\Requests\Api\V2\StoreBccMapRequest;
use App\Http\Requests\Api\V2\UpdateBccMapRequest;
use App\Http\Resources\Api\V2\RoutingResource;
use App\Policies\RoutingPolicy;
use App\Services\Routing\BccDestinationChoiceQuery;
use App\Services\Routing\BccMapQuery;
use App\Services\Routing\BccMapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * listBccMaps, getBccMap, createBccMap, updateBccMap, deleteBccMap and listBccDestinationChoices
 * (feature 009 US5). Domain administrators reach the maps of their domains and alias domains;
 * writes need `bccMaps`. Maps outside the scope answer `404` like unknown ones.
 */
class BccMapController extends Controller
{
    public function __construct(
        private readonly BccMapQuery $query,
        private readonly RoutingPolicy $policy,
    ) {}

    public function index(ListBccMapsRequest $request): JsonResponse
    {
        return RoutingResource::list($this->query->page(RequestContext::requirePrincipal($request), $request->listQuery(), $request->filters()));
    }

    public function choices(ListBccDestinationChoicesRequest $request, BccDestinationChoiceQuery $choices): JsonResponse
    {
        return RoutingResource::list($choices->page(RequestContext::requirePrincipal($request), $request->listQuery(), $request->filters()));
    }

    public function show(Request $request, string $bccMapId): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $this->policy->authorize($principal, RoutingPolicy::VIEW_BCC_MAPS);

        return RoutingResource::response($this->found($request, $bccMapId));
    }

    public function store(StoreBccMapRequest $request, BccMapService $service): JsonResponse
    {
        $id = $service->create(RequestContext::requirePrincipal($request), $request->members());

        return RoutingResource::response($this->found($request, (string) $id), 201)->header('Location', '/api/v2/bcc-maps/'.$id);
    }

    public function update(UpdateBccMapRequest $request, BccMapService $service): JsonResponse
    {
        $service->update(RequestContext::requirePrincipal($request), $request->bccMapId(), $request->members(), $request->header('If-Match'));

        return RoutingResource::response($this->found($request, (string) $request->bccMapId()));
    }

    public function destroy(Request $request, string $bccMapId, BccMapService $service): Response
    {
        $principal = RequestContext::requirePrincipal($request);
        $this->policy->authorize($principal, RoutingPolicy::CHANGE_BCC_MAPS);
        $id = $this->query->resolve($principal, $bccMapId) ?? throw new NotFoundHttpException('The BCC map does not exist.');

        $service->delete($principal, $id, $request->header('If-Match'));

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function found(Request $request, string $bccMapId): array
    {
        $principal = RequestContext::requirePrincipal($request);
        $id = $this->query->resolve($principal, $bccMapId);
        $row = $id === null ? null : $this->query->find($principal, $id);

        return $row === null ? throw new NotFoundHttpException('The BCC map does not exist.') : BccMapQuery::present($row);
    }
}
