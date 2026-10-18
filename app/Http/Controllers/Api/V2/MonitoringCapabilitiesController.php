<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\Monitoring\MonitoringCapabilitiesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * getMonitoringCapabilities (feature 011 FR-069): the settings and limits clients need before they
 * poll, page or act.
 */
class MonitoringCapabilitiesController extends Controller
{
    public function show(Request $request, MonitoringCapabilitiesService $capabilities): JsonResponse
    {
        return response()->json(['data' => $capabilities->capabilities((string) $request->ip())], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
