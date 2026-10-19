<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\ValidationProblemException;
use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ApiFormRequest;
use App\Http\Resources\Api\V2\SettingsResource;
use App\Policies\SettingsPolicy;
use App\Services\Settings\BrandingService;
use App\Support\Images\ImageInspector;
use App\Support\Images\ImageRejected;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * getLogoSetting, replaceLogo and deleteLogo (feature 013 FR-012 to FR-018). The upload is the raw
 * request body; its type comes from the content (ImageInspector), and a refused image is reported on
 * the field `body`. Administrators only; changes are refused in demo mode.
 */
class LogoSettingController extends Controller
{
    public function show(Request $request, string $variant, SettingsPolicy $policy, BrandingService $branding): JsonResponse
    {
        $policy->requireAdmin(RequestContext::requirePrincipal($request));

        return SettingsResource::response($branding->logo($variant));
    }

    public function update(Request $request, string $variant, SettingsPolicy $policy, BrandingService $branding, ImageInspector $images): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $policy->requireCustomisation($principal);
        $bytes = (string) $request->getContent();

        try {
            $inspection = $images->inspect($bytes, $branding->logoLimits());
        } catch (ImageRejected $e) {
            throw new ValidationProblemException(['body' => [ApiFormRequest::make($e->rule, $e->params)]]);
        }

        return SettingsResource::response($branding->replaceLogo($variant, $bytes, $inspection, $request->header('If-Match'), $principal));
    }

    public function destroy(Request $request, string $variant, SettingsPolicy $policy, BrandingService $branding): Response
    {
        $principal = RequestContext::requirePrincipal($request);
        $policy->requireCustomisation($principal);
        $branding->deleteLogo($variant, $request->header('If-Match'), $principal);

        return response()->noContent();
    }
}
