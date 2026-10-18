<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\Rspamd\RspamdStatisticsService;
use Illuminate\Http\JsonResponse;

/**
 * getRspamdStatistics (feature 011 FR-035, FR-036).
 */
class RspamdStatisticsController extends Controller
{
    public function show(RspamdStatisticsService $statistics): JsonResponse
    {
        return response()->json(['data' => $statistics->statistics()], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
