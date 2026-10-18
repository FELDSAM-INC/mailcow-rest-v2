<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ListAliasesRequest;
use App\Http\Requests\Api\V2\StoreAliasRequest;
use App\Http\Requests\Api\V2\UpdateAliasRequest;
use App\Http\Resources\Api\V2\AliasResource;
use App\Models\Mailcow\Alias;
use App\Services\Aliases\AliasCreator;
use App\Services\Aliases\AliasDeleter;
use App\Services\Aliases\AliasPresenter;
use App\Services\Aliases\AliasQuery;
use App\Services\Aliases\AliasUpdater;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * listAliases, getAlias, createAlias, updateAlias and deleteAlias (feature 004). The role is checked
 * first (`403`), then the scope (`404`, also for a mailbox's own row), then the request.
 */
class AliasController extends Controller
{
    public function __construct(
        private readonly AliasQuery $query,
        private readonly AliasPresenter $presenter,
    ) {}

    public function index(ListAliasesRequest $request): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $list = $request->listQuery();
        $page = $this->query->page($principal, $list, $request->filters());

        return AliasResource::list(array_map($this->presenter->present(...), $page['rows']), $list->meta($page['total']));
    }

    public function store(StoreAliasRequest $request, AliasCreator $creator): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $id = $creator->create($principal, $request->members());
        $row = $this->query->find($principal, $id) ?? throw new NotFoundHttpException('The alias does not exist.');

        return AliasResource::response($this->presenter->present($row), 201)->header('Location', '/api/v2/aliases/'.$id);
    }

    public function show(Request $request, string $aliasId): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        Gate::forUser($principal)->authorize('view', Alias::class);

        return AliasResource::response($this->presenter->present($this->found($request, $aliasId)));
    }

    public function update(UpdateAliasRequest $request, AliasUpdater $updater): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $updater->update($principal, $request->aliasId(), $request->members(), $request->header('If-Match'));
        $row = $this->query->find($principal, $request->aliasId()) ?? throw new NotFoundHttpException('The alias does not exist.');

        return AliasResource::response($this->presenter->present($row));
    }

    public function destroy(Request $request, string $aliasId, AliasDeleter $deleter): Response
    {
        $principal = RequestContext::requirePrincipal($request);
        Gate::forUser($principal)->authorize('delete', Alias::class);

        $id = $this->query->resolve($principal, $aliasId) ?? throw new NotFoundHttpException('The alias does not exist.');
        $deleter->delete($principal, $id, $request->header('If-Match'));

        return response()->noContent();
    }

    /** The alias of the path in the caller's scope, or `404`. */
    private function found(Request $request, string $aliasId): object
    {
        $principal = RequestContext::requirePrincipal($request);
        $id = $this->query->resolve($principal, $aliasId);

        return ($id === null ? null : $this->query->find($principal, $id)) ?? throw new NotFoundHttpException('The alias does not exist.');
    }
}
