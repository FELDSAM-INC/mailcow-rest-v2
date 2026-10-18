<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Services\SelfService\SelfServiceCapabilitiesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * getSelfServiceCapabilities (feature 005 FR-080): public, also for restricted sessions and
 * read-only keys; signed-out callers get only the reset availability, sessions also their account.
 */
class SelfServiceCapabilitiesController extends Controller
{
    public function show(Request $request, SelfServiceCapabilitiesService $capabilities): JsonResponse
    {
        $principal = RequestContext::principal($request);

        return response()->json(
            ['data' => $capabilities->describe($principal, $principal !== null && ! RequestContext::isApiKeyRequest($request))],
            200,
            [],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
