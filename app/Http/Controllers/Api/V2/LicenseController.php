<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Policies\SettingsPolicy;
use App\Services\Settings\LicenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * getLicenseStatus (feature 013 FR-082, FR-084, FR-085): never contacts the license service.
 */
class LicenseController extends Controller
{
    public function show(Request $request, SettingsPolicy $policy, LicenseService $license): JsonResponse
    {
        $policy->requireAdmin(RequestContext::requirePrincipal($request));
        LicenseService::refuseWhenDisabled();

        return response()->json(['data' => $license->status()]);
    }
}
