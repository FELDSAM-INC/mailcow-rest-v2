<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Resources\Api\V2\SettingsResource;
use App\Policies\SettingsPolicy;
use App\Services\Settings\SettingsCapabilitiesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * getSettingsCapabilities (feature 013 FR-094): administrators, read-only keys included.
 */
class SettingsCapabilitiesController extends Controller
{
    public function show(Request $request, SettingsPolicy $policy, SettingsCapabilitiesService $capabilities): JsonResponse
    {
        $policy->requireAdmin(RequestContext::requirePrincipal($request));

        return SettingsResource::response($capabilities->get());
    }
}
