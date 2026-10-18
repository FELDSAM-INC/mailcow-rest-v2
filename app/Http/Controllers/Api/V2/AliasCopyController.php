<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Models\Mailcow\Alias;
use App\Services\Aliases\AliasCopier;
use App\Services\Aliases\AliasQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * copyAliasOverAliasDomains (feature 004 FR-036, FR-037): the role first (`403`), then the alias in
 * the caller's scope (`404`), then the copies; `200` with one outcome per alias domain.
 */
class AliasCopyController extends Controller
{
    public function store(Request $request, string $aliasId, AliasQuery $query, AliasCopier $copier): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        Gate::forUser($principal)->authorize('create', Alias::class);

        $id = $query->resolve($principal, $aliasId) ?? throw new NotFoundHttpException('The alias does not exist.');

        return response()->json(['data' => $copier->copy($principal, $id, $request->header('If-Match'))], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
