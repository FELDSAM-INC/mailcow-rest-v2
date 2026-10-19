<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Settings\UpdateAppLinkSettingsRequest;
use App\Http\Resources\Api\V2\SettingsResource;
use App\Policies\SettingsPolicy;
use App\Services\Settings\BrandingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * getAppLinkSettings and updateAppLinkSettings (feature 013 FR-025 to FR-028).
 */
class AppLinkSettingsController extends Controller
{
    public function show(Request $request, SettingsPolicy $policy, BrandingService $branding): JsonResponse
    {
        $policy->requireAdmin(RequestContext::requirePrincipal($request));

        return SettingsResource::response($branding->appLinks());
    }

    public function update(UpdateAppLinkSettingsRequest $request, BrandingService $branding): JsonResponse
    {
        return SettingsResource::response($branding->updateAppLinks($request->links(), $request->header('If-Match'), RequestContext::requirePrincipal($request)));
    }
}
