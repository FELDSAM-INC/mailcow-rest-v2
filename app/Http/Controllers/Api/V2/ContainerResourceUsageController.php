<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\Monitoring\ContainerResourceUsageService;
use Illuminate\Http\JsonResponse;

/**
 * getContainerResourceUsage (feature 011 FR-011): counters and rates of a running container.
 */
class ContainerResourceUsageController extends Controller
{
    public function show(string $containerName, ContainerResourceUsageService $usage): JsonResponse
    {
        return response()->json(['data' => $usage->usage($containerName)], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
