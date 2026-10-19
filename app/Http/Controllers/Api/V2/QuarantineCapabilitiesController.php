<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Policies\QuarantinePolicy;
use App\Services\Quarantine\QuarantineCapabilities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * getQuarantineCapabilities (feature 006 FR-072): every role and both key types.
 */
class QuarantineCapabilitiesController extends Controller
{
    public function show(Request $request, QuarantinePolicy $policy, QuarantineCapabilities $capabilities): JsonResponse
    {
        $policy->authorize(RequestContext::requirePrincipal($request), QuarantinePolicy::VIEW_CAPABILITIES);

        return response()->json(['data' => $capabilities->read()], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
