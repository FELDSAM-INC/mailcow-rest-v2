<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\DkimKeyBulkActionRequest;
use App\Services\Dkim\DkimKeyService;
use App\Support\Bulk\BulkActionRunner;
use Illuminate\Http\JsonResponse;

/**
 * applyDkimKeyBulkAction (feature 010 FR-059): one result per distinct name, status 200; while the
 * DKIM DNS publication is enabled, `meta.dnsPublication` reports each deleted key's record removal.
 */
class DkimKeyBulkActionController extends Controller
{
    public function store(DkimKeyBulkActionRequest $request, DkimKeyService $service, BulkActionRunner $runner): JsonResponse
    {
        return response()->json($service->bulkDelete($request, RequestContext::requirePrincipal($request), $request->targets(), $runner), 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
