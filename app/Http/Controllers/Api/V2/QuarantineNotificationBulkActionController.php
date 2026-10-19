<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\QuarantineNotificationBulkRequest;
use App\Services\Quarantine\QuarantineNotificationBulkActions;
use Illuminate\Http\JsonResponse;

/**
 * applyQuarantineNotificationBulkAction (feature 006 FR-037): the shared `BulkResult`.
 */
class QuarantineNotificationBulkActionController extends Controller
{
    public function store(QuarantineNotificationBulkRequest $request, QuarantineNotificationBulkActions $bulk): JsonResponse
    {
        $result = $bulk->apply($request, RequestContext::requirePrincipal($request), $request->patch(), $request->targets());

        return response()->json(['data' => $result], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
