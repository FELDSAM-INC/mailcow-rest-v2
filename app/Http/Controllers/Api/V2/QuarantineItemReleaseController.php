<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ReleaseQuarantineItemRequest;
use App\Http\Resources\Api\V2\QuarantineActionReportResource;
use App\Services\Quarantine\QuarantineActionService;
use Illuminate\Http\JsonResponse;

/**
 * releaseQuarantineItem (feature 006 FR-019 to FR-022, FR-025, FR-026).
 */
class QuarantineItemReleaseController extends Controller
{
    public function store(ReleaseQuarantineItemRequest $request, string $itemId, QuarantineActionService $actions): JsonResponse
    {
        return QuarantineActionReportResource::response($actions->release(RequestContext::requirePrincipal($request), (int) $itemId, $request->learnAsHam(), $request->header('If-Match')));
    }
}
