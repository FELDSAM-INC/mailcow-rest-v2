<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Resources\Api\V2\Oauth2ClientCapabilitiesResource;
use App\Models\Mailcow\Oauth2Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * getOauth2ClientsCapabilities (FR-064): administrators and their keys, read-only included.
 */
class Oauth2ClientCapabilitiesController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        Gate::forUser(RequestContext::requirePrincipal($request))->authorize('viewAny', Oauth2Client::class);

        return response()->json(['data' => Oauth2ClientCapabilitiesResource::build()->resource], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
