<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Services\SpamPolicy\SpamFilterStatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * getSpamFilterActionStatistics (feature 006 FR-058).
 */
class SpamFilterStatisticsController extends Controller
{
    public function show(Request $request, SpamFilterStatisticsService $statistics): JsonResponse
    {
        return response()->json(['data' => $statistics->statistics(RequestContext::requirePrincipal($request))], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
