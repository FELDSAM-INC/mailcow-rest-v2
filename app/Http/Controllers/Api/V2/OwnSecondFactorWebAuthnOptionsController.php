<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Services\SecondFactors\SecondFactorEnrolmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * createOwnSecondFactorWebAuthnOptions (feature 005 FR-031): single-use registration options for a
 * security key, bound to the session. A ceremony request: not audited.
 */
class OwnSecondFactorWebAuthnOptionsController extends Controller
{
    public function store(Request $request, SecondFactorEnrolmentService $enrolment): JsonResponse
    {
        $options = $enrolment->webauthnOptions($request, RequestContext::requirePrincipal($request));

        return response()->json(['data' => [
            'publicKey' => $options['publicKey'],
            'expiresAt' => $options['expiresAt']->format('Y-m-d\TH:i:s\Z'),
        ]], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
