<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\DomainBulkActionRequest;
use App\Services\Domains\DomainBulkActions;
use Illuminate\Http\JsonResponse;

/**
 * applyDomainBulkAction (FR-062, FR-063): one result per distinct domain, status 200.
 */
class DomainBulkActionController extends Controller
{
    public function store(DomainBulkActionRequest $request, DomainBulkActions $bulk): JsonResponse
    {
        $result = $bulk->apply($request, RequestContext::requirePrincipal($request), (string) $request->validated('action'), $request->targets());

        return response()->json(['data' => $result], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
