<?php

namespace App\Http\Controllers\Api\V2;

use App\Auth\Principal;
use App\Clients\SharedStore\SharedStore;
use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ListAliasDomainsRequest;
use App\Http\Requests\Api\V2\StoreAliasDomainRequest;
use App\Http\Requests\Api\V2\UpdateAliasDomainRequest;
use App\Http\Resources\Api\V2\AliasResource;
use App\Models\Mailcow\Alias;
use App\Services\AliasDomains\AliasDomainCreator;
use App\Services\AliasDomains\AliasDomainDeleter;
use App\Services\AliasDomains\AliasDomainPresenter;
use App\Services\AliasDomains\AliasDomainQuery;
use App\Services\AliasDomains\AliasDomainUpdater;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * listAliasDomains, getAliasDomain, createAliasDomain, updateAliasDomain and deleteAliasDomain
 * (feature 004). Representations need the rate limit from mailcow's shared store, so they answer
 * `503 temporary-failure` when it is down; a change checks the store before it writes, so that it
 * is never committed without a representation to return.
 */
class AliasDomainController extends Controller
{
    public function __construct(
        private readonly AliasDomainQuery $query,
        private readonly AliasDomainPresenter $presenter,
    ) {}

    public function index(ListAliasDomainsRequest $request): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $list = $request->listQuery();
        $page = $this->query->page($principal, $list, $request->filters());

        return AliasResource::list($this->presenter->present($page['rows']), $list->meta($page['total']));
    }

    public function show(Request $request, string $aliasDomain): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        Gate::forUser($principal)->authorize('view', Alias::class);

        $name = $this->query->resolve($principal, $aliasDomain) ?? throw new NotFoundHttpException('The alias domain does not exist.');

        return AliasResource::response($this->presenter->presentOne($this->row($principal, $name)));
    }

    public function store(StoreAliasDomainRequest $request, AliasDomainCreator $creator): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $creation = $creator->create($principal, $request->members());
        $data = $this->presenter->presentOne($this->row($principal, $creation->name));
        $data['creationReport'] = $creation->report->toArray();

        return AliasResource::response($data, 201)->header('Location', '/api/v2/alias-domains/'.rawurlencode($creation->name));
    }

    public function update(UpdateAliasDomainRequest $request, AliasDomainUpdater $updater, SharedStore $store): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $store->ping();
        $updater->update($principal, $request->aliasDomain(), $request->members(), $request->header('If-Match'));

        return AliasResource::response($this->presenter->presentOne($this->row($principal, $request->aliasDomain())));
    }

    public function destroy(Request $request, string $aliasDomain, AliasDomainDeleter $deleter): Response
    {
        $principal = RequestContext::requirePrincipal($request);
        Gate::forUser($principal)->authorize('delete', Alias::class);

        $name = $this->query->resolve($principal, $aliasDomain) ?? throw new NotFoundHttpException('The alias domain does not exist.');
        $deleter->delete($principal, $name, $request->header('If-Match'));

        return response()->noContent();
    }

    private function row(Principal $principal, string $name): object
    {
        return $this->query->find($principal, $name) ?? throw new NotFoundHttpException('The alias domain does not exist.');
    }
}
