<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Policies\SettingsPolicy;
use App\Services\Settings\LicenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * verifyLicense (feature 013 FR-083 to FR-085): asks the license service now; an unreachable service
 * is a result, not an error.
 */
class LicenseVerificationController extends Controller
{
    public function store(Request $request, SettingsPolicy $policy, LicenseService $license): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $policy->requireAdmin($principal);
        LicenseService::refuseWhenDisabled();

        return response()->json(['data' => $license->verify($principal)]);
    }
}
