<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\SyncJobBulkActionRequest;
use App\Services\SyncJobs\SyncJobBulkService;
use Illuminate\Http\JsonResponse;

/**
 * applySyncJobBulkAction (feature 008 FR-023, FR-024): one result per distinct job, status 200.
 */
class SyncJobBulkActionController extends Controller
{
    public function store(SyncJobBulkActionRequest $request, SyncJobBulkService $bulk): JsonResponse
    {
        $result = $bulk->apply($request, RequestContext::requirePrincipal($request), $request->action(), $request->targets());

        return response()->json(['data' => $result], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
