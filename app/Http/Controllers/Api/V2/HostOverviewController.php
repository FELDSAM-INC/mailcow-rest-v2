<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\Monitoring\HostOverviewService;
use Illuminate\Http\JsonResponse;

/**
 * getHostOverview (feature 011 FR-005, FR-006): the system overview of the mailcow host.
 */
class HostOverviewController extends Controller
{
    public function show(HostOverviewService $overview): JsonResponse
    {
        return response()->json(['data' => $overview->overview()], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
