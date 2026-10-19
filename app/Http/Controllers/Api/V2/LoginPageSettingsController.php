<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Settings\UpdateLoginPageSettingsRequest;
use App\Http\Resources\Api\V2\SettingsResource;
use App\Policies\SettingsPolicy;
use App\Services\Settings\BrandingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * getLoginPageSettings and updateLoginPageSettings (feature 013 FR-024).
 */
class LoginPageSettingsController extends Controller
{
    public function show(Request $request, SettingsPolicy $policy, BrandingService $branding): JsonResponse
    {
        $policy->requireAdmin(RequestContext::requirePrincipal($request));

        return SettingsResource::response($branding->loginPage());
    }

    public function update(UpdateLoginPageSettingsRequest $request, BrandingService $branding): JsonResponse
    {
        /** @var array<string, bool> $patch */
        $patch = $request->patch();

        return SettingsResource::response($branding->updateLoginPage($patch, $request->header('If-Match'), RequestContext::requirePrincipal($request)));
    }
}
