<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\Monitoring\InstallationVersionService;
use Illuminate\Http\JsonResponse;

/**
 * getInstallationVersion (feature 011 FR-018): the installed mailcow version.
 */
class InstallationController extends Controller
{
    public function show(InstallationVersionService $installation): JsonResponse
    {
        return response()->json(['data' => $installation->current()->toArray()], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
