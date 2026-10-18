<?php

namespace App\Http\Controllers\Api\V2;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ListResourcesRequest;
use App\Http\Requests\Api\V2\StoreResourceRequest;
use App\Http\Requests\Api\V2\UpdateResourceRequest;
use App\Http\Resources\Api\V2\AliasResource;
use App\Models\Mailcow\Alias;
use App\Services\Resources\ResourceCreator;
use App\Services\Resources\ResourceDeleter;
use App\Services\Resources\ResourcePresenter;
use App\Services\Resources\ResourceQuery;
use App\Services\Resources\ResourceUpdater;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * listResources, getResource, createResource, updateResource and deleteResource (feature 004
 * FR-046–FR-052): the role first (`403`), then the resource in the caller's scope (`404`, also for a
 * mailbox address), then the request.
 */
class ResourceController extends Controller
{
    public function __construct(
        private readonly ResourceQuery $query,
        private readonly ResourcePresenter $presenter,
    ) {}

    public function index(ListResourcesRequest $request): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $list = $request->listQuery();
        $page = $this->query->page($principal, $list, $request->filters());

        return AliasResource::list(array_map($this->presenter->present(...), $page['rows']), $list->meta($page['total']));
    }

    public function show(Request $request, string $resourceAddress): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        Gate::forUser($principal)->authorize('view', Alias::class);

        $address = $this->query->resolve($principal, $resourceAddress) ?? throw new NotFoundHttpException('The resource does not exist.');

        return AliasResource::response($this->presenter->present($this->row($principal, $address)));
    }

    public function store(StoreResourceRequest $request, ResourceCreator $creator): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $address = $creator->create($principal, $request->members());

        return AliasResource::response($this->presenter->present($this->row($principal, $address)), 201)
            ->header('Location', '/api/v2/resources/'.str_replace('%40', '@', rawurlencode($address)));
    }

    public function update(UpdateResourceRequest $request, ResourceUpdater $updater): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $updater->update($principal, $request->resource(), $request->members(), $request->header('If-Match'));

        return AliasResource::response($this->presenter->present($this->row($principal, $request->resource())));
    }

    public function destroy(Request $request, string $resourceAddress, ResourceDeleter $deleter): Response
    {
        $principal = RequestContext::requirePrincipal($request);
        Gate::forUser($principal)->authorize('delete', Alias::class);

        $address = $this->query->resolve($principal, $resourceAddress) ?? throw new NotFoundHttpException('The resource does not exist.');
        $deleter->delete($principal, $address, $request->header('If-Match'));

        return response()->noContent();
    }

    private function row(Principal $principal, string $address): object
    {
        return $this->query->find($principal, $address) ?? throw new NotFoundHttpException('The resource does not exist.');
    }
}
