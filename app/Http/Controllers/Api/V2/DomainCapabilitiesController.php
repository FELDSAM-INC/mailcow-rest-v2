<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Models\Mailcow\Domain;
use App\Services\Domains\DomainCapabilitiesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * getDomainsCapabilities (FR-064): the settings and limits the domain forms need.
 */
class DomainCapabilitiesController extends Controller
{
    public function show(Request $request, DomainCapabilitiesService $capabilities): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        Gate::forUser($principal)->authorize('viewAny', Domain::class);

        return response()->json(['data' => $capabilities->for($principal)], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
