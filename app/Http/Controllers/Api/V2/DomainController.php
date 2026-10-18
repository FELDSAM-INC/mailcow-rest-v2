<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ListDomainsRequest;
use App\Http\Requests\Api\V2\StoreDomainRequest;
use App\Http\Resources\Api\V2\DomainResource;
use App\Models\Mailcow\Domain;
use App\Services\Domains\DomainCreator;
use App\Services\Domains\DomainPresenter;
use App\Services\Domains\DomainQuery;
use App\Services\Domains\DomainScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * listDomains, getDomain, createDomain, updateDomain and deleteDomain (feature 002). The role is
 * checked first (`403`), then the scope (`404`, also for alias domain names), then the request.
 */
class DomainController extends Controller
{
    public function __construct(
        private readonly DomainPresenter $presenter,
        private readonly DomainScope $scope,
    ) {}

    public function index(ListDomainsRequest $request, DomainQuery $query): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $list = $request->listQuery();
        $page = $query->page($principal, $list, $request->filters());

        return DomainResource::list($this->presenter->present($page['rows'], $principal), $list->meta($page['total']));
    }

    public function show(Request $request, string $domain): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        Gate::forUser($principal)->authorize('view', Domain::class);

        $name = $this->scope->resolve($principal, $domain) ?? throw new NotFoundHttpException('The domain does not exist.');
        $row = DB::table('domain')->where('domain', $name)->first() ?? throw new NotFoundHttpException('The domain does not exist.');

        return DomainResource::response($this->presenter->presentOne($row, $principal));
    }

    public function store(StoreDomainRequest $request, DomainCreator $creator): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $creation = $creator->create($principal, $request->members());
        $row = DB::table('domain')->where('domain', $creation->name)->first() ?? throw new NotFoundHttpException('The domain does not exist.');
        $data = $this->presenter->presentOne($row, $principal);
        $data['creationReport'] = $creation->report->toArray();

        return DomainResource::response($data, 201)->header('Location', '/api/v2/domains/'.rawurlencode($creation->name));
    }
}
