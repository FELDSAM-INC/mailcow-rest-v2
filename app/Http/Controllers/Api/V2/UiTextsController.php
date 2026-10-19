<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Settings\UpdateUiTextsRequest;
use App\Http\Resources\Api\V2\SettingsResource;
use App\Policies\SettingsPolicy;
use App\Services\Settings\BrandingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * getUiTexts and updateUiTexts (feature 013 FR-019 to FR-023).
 */
class UiTextsController extends Controller
{
    public function show(Request $request, SettingsPolicy $policy, BrandingService $branding): JsonResponse
    {
        $policy->requireAdmin(RequestContext::requirePrincipal($request));

        return SettingsResource::response($branding->uiTexts());
    }

    public function update(UpdateUiTextsRequest $request, BrandingService $branding): JsonResponse
    {
        return SettingsResource::response($branding->updateUiTexts($request->patch(), $request->header('If-Match'), RequestContext::requirePrincipal($request)));
    }
}
