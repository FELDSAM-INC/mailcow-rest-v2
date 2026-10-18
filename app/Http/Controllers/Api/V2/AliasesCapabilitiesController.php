<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Models\Mailcow\Alias;
use App\Services\Aliases\AliasesCapabilitiesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * getAliasesCapabilities (feature 004 FR-068): administrators and domain administrators, with a
 * session or any key (read only).
 */
class AliasesCapabilitiesController extends Controller
{
    public function show(Request $request, AliasesCapabilitiesService $capabilities): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        Gate::forUser($principal)->authorize('viewAny', Alias::class);

        return response()->json(['data' => $capabilities->for($principal)], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
