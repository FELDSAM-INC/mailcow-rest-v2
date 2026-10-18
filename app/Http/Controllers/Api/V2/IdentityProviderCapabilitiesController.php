<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Resources\Api\V2\IdentityProviderCapabilitiesResource;
use App\Models\Mailcow\IdentityProviderSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * getIdentityProviderCapabilities (FR-064): administrators and their keys, read-only included.
 */
class IdentityProviderCapabilitiesController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        Gate::forUser(RequestContext::requirePrincipal($request))->authorize('view', IdentityProviderSetting::class);

        return response()->json(['data' => IdentityProviderCapabilitiesResource::build()->resource], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
