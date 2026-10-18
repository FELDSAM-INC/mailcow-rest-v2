<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\GenerateDkimKeyRequest;
use App\Http\Requests\Api\V2\ListDkimKeysRequest;
use App\Http\Resources\Api\V2\DkimKeyChangeResource;
use App\Http\Resources\Api\V2\DkimKeyResource;
use App\Services\Dkim\DkimEntryQuery;
use App\Services\Dkim\DkimKeyService;
use App\Services\Dkim\DkimNameResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * listDkimKeys and getDkimKey (feature 010 US1), and the key operations of later stories. The role
 * is checked first (`403`), then the name in the caller's scope (`404`, identical for a name that
 * does not exist). mailcow's shared store being unavailable answers `503 temporary-failure`.
 */
class DkimKeyController extends Controller
{
    public function __construct(
        private readonly DkimEntryQuery $query,
        private readonly DkimNameResolver $names,
    ) {}

    public function index(ListDkimKeysRequest $request): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $list = $request->listQuery();
        $page = $this->query->page($principal, $request->filters(), $list);

        return DkimKeyResource::list($page['entries'], $list->meta($page['total']));
    }

    public function store(GenerateDkimKeyRequest $request, DkimKeyService $service): JsonResponse
    {
        $change = $service->generate(
            RequestContext::requirePrincipal($request),
            $request->name(),
            $request->selector(),
            $request->keySize(),
            $request->overwrite(),
            $request->header('If-Match'),
        );

        return DkimKeyChangeResource::response($change);
    }

    public function show(Request $request, string $dkimName): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        Gate::forUser($principal)->authorize('dkim.view');

        $name = $this->names->resolve($principal, $dkimName) ?? throw new NotFoundHttpException('The DKIM entry does not exist.');
        $entry = $this->query->entry($name);

        if (! $name->isMailDomain() && $entry->key === null) {
            throw new NotFoundHttpException('The DKIM entry does not exist.');
        }

        return DkimKeyResource::response($entry);
    }
}
