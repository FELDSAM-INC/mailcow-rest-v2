<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Policies\RoutingPolicy;
use App\Services\Routing\RoutingCapabilitiesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * getRoutingCapabilities (feature 009 FR-093): administrators and domain administrators; read-only
 * keys may read.
 */
class RoutingCapabilitiesController extends Controller
{
    public function show(Request $request, RoutingPolicy $policy, RoutingCapabilitiesService $capabilities): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $policy->authorize($principal, RoutingPolicy::VIEW_ROUTING_CAPABILITIES);

        return response()->json(['data' => $capabilities->for($principal)], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
