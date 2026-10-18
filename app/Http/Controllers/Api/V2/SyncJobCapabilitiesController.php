<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Policies\SyncJobPolicy;
use App\Services\SyncJobs\SyncJobCapabilities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * getSyncJobsCapabilities (feature 008 FR-030): read-only keys may use it; callers without the
 * sync-jobs permission get `403 permission-flag-required`.
 */
class SyncJobCapabilitiesController extends Controller
{
    public function show(Request $request, SyncJobPolicy $policy, SyncJobCapabilities $capabilities): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $policy->authorize($principal);

        return response()->json(['data' => $capabilities->for($principal)], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
