<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\AliasBulkActionRequest;
use App\Services\Aliases\AliasBulkActions;
use Illuminate\Http\JsonResponse;

/**
 * applyAliasBulkAction (feature 004 FR-058, FR-059): one result per distinct alias, status 200,
 * with `meta.copies` for `copyOverAliasDomains`.
 */
class AliasBulkActionController extends Controller
{
    public function store(AliasBulkActionRequest $request, AliasBulkActions $bulk): JsonResponse
    {
        $body = $bulk->apply($request, RequestContext::requirePrincipal($request), (string) $request->validated('action'), $request->targets());

        return response()->json($body, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
