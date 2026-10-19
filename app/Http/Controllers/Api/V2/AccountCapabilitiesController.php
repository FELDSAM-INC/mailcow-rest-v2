<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Policies\AdminPolicy;
use App\Services\Accounts\AccountCapabilitiesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * getAccountCapabilities (feature 007 FR-077): administrators, with a session or a key (read-only
 * keys included).
 */
class AccountCapabilitiesController extends Controller
{
    public function show(Request $request, AccountCapabilitiesService $capabilities, AdminPolicy $policy): JsonResponse
    {
        $policy->authorize(RequestContext::requirePrincipal($request), AdminPolicy::VIEW);

        return response()->json(['data' => $capabilities->capabilities()], 200, [], JSON_UNESCAPED_SLASHES);
    }
}
