<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\Monitoring\UpdateCheckService;
use Illuminate\Http\JsonResponse;

/**
 * getUpdateStatus (feature 011 FR-019, FR-020): whether a newer mailcow release exists.
 */
class UpdateStatusController extends Controller
{
    public function show(UpdateCheckService $status): JsonResponse
    {
        return response()->json(['data' => $status->status()], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
