<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\IdentityProvider\SingleSignOnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * startSingleSignOn and cancelSingleSignOn (FR-021): ceremony requests of the browser session, not
 * audited.
 */
class SingleSignOnController extends Controller
{
    public function store(Request $request, SingleSignOnService $sso): JsonResponse
    {
        $started = $sso->start($request);

        return response()->json(['data' => [
            'authorizationUrl' => $started['authorizationUrl'],
            'expiresAt' => $started['expiresAt']->format('Y-m-d\TH:i:s\Z'),
        ]], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function destroy(Request $request, SingleSignOnService $sso): Response
    {
        $sso->cancel($request);

        return response()->noContent();
    }
}
