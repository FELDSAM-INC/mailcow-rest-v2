<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\AliasDomainBulkActionRequest;
use App\Services\AliasDomains\AliasDomainBulkActions;
use Illuminate\Http\JsonResponse;

/**
 * applyAliasDomainBulkAction (feature 004 FR-058, FR-059): one result per distinct alias domain.
 */
class AliasDomainBulkActionController extends Controller
{
    public function store(AliasDomainBulkActionRequest $request, AliasDomainBulkActions $bulk): JsonResponse
    {
        $result = $bulk->apply($request, RequestContext::requirePrincipal($request), (string) $request->validated('action'), $request->targets());

        return response()->json(['data' => $result], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
