<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Settings\UpdateIpCheckSettingRequest;
use App\Http\Resources\Api\V2\SettingsResource;
use App\Policies\SettingsPolicy;
use App\Services\Settings\BrandingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * getIpCheckSetting and updateIpCheckSetting (feature 013 FR-035): the opt-in that feature 011's
 * public address lookup requires.
 */
class IpCheckSettingController extends Controller
{
    public function show(Request $request, SettingsPolicy $policy, BrandingService $branding): JsonResponse
    {
        $policy->requireAdmin(RequestContext::requirePrincipal($request));

        return SettingsResource::response($branding->ipCheck());
    }

    public function update(UpdateIpCheckSettingRequest $request, BrandingService $branding): JsonResponse
    {
        return SettingsResource::response($branding->updateIpCheck($request->enabled(), $request->header('If-Match'), RequestContext::requirePrincipal($request)));
    }
}
