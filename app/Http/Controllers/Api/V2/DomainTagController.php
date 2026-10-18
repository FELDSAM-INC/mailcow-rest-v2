<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ListDomainTagsRequest;
use App\Services\Domains\DomainTagSummaryQuery;
use Illuminate\Http\JsonResponse;

/**
 * listDomainTags (FR-066): the distinct tags of the domains in the caller's scope.
 */
class DomainTagController extends Controller
{
    public function index(ListDomainTagsRequest $request, DomainTagSummaryQuery $tags): JsonResponse
    {
        return response()->json($tags->page(RequestContext::requirePrincipal($request), $request->listQuery()), 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
