<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\QuarantineBulkActionRequest;
use App\Services\Quarantine\QuarantineBulkActions;
use Illuminate\Http\JsonResponse;

/**
 * applyQuarantineBulkAction (feature 006 FR-027): the contract's `QuarantineBulkResult`.
 */
class QuarantineBulkActionController extends Controller
{
    public function store(QuarantineBulkActionRequest $request, QuarantineBulkActions $bulk): JsonResponse
    {
        $result = $bulk->apply($request, RequestContext::requirePrincipal($request), $request->action(), $request->targets());

        return response()->json(['data' => $result], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
