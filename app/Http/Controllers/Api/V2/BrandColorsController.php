<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Settings\UpdateBrandColorsRequest;
use App\Http\Resources\Api\V2\SettingsResource;
use App\Policies\SettingsPolicy;
use App\Services\Settings\BrandColorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * getBrandColors and updateBrandColors (feature 013 FR-029 to FR-034).
 */
class BrandColorsController extends Controller
{
    public function show(Request $request, SettingsPolicy $policy, BrandColorService $colors): JsonResponse
    {
        $policy->requireAdmin(RequestContext::requirePrincipal($request));

        return SettingsResource::response($colors->read());
    }

    public function update(UpdateBrandColorsRequest $request, BrandColorService $colors): JsonResponse
    {
        /** @var array<string, string|null> $patch */
        $patch = $request->patch();

        return SettingsResource::response($colors->update($patch, $request->header('If-Match'), RequestContext::requirePrincipal($request)));
    }
}
