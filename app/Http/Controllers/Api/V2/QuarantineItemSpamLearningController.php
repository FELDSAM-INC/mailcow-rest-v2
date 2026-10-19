<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Resources\Api\V2\QuarantineActionReportResource;
use App\Services\Quarantine\QuarantineActionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * learnQuarantineItemAsSpam (feature 006 FR-023, FR-025, FR-026).
 */
class QuarantineItemSpamLearningController extends Controller
{
    public function store(Request $request, string $itemId, QuarantineActionService $actions): JsonResponse
    {
        return QuarantineActionReportResource::response($actions->learnAsSpam(RequestContext::requirePrincipal($request), (int) $itemId, $request->header('If-Match')));
    }
}
