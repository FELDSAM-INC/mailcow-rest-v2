<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Settings\UpdateLegacyApiCorsRequest;
use App\Http\Resources\Api\V2\SettingsResource;
use App\Policies\SettingsPolicy;
use App\Services\Settings\LegacyApiCorsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * getLegacyApiCorsSettings and updateLegacyApiCorsSettings (feature 013 FR-068 to FR-071).
 */
class LegacyApiCorsController extends Controller
{
    public function show(Request $request, SettingsPolicy $policy, LegacyApiCorsService $cors): JsonResponse
    {
        $policy->requireAdmin(RequestContext::requirePrincipal($request));

        return SettingsResource::response($cors->read());
    }

    public function update(UpdateLegacyApiCorsRequest $request, LegacyApiCorsService $cors): JsonResponse
    {
        /** @var array{allowedOrigins?: list<string>, allowedMethods?: list<string>} $patch */
        $patch = $request->patch();

        return SettingsResource::response($cors->update($patch, $request->header('If-Match'), RequestContext::requirePrincipal($request)));
    }
}
