<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Services\SecondFactors\SecondFactorEnrolmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * startOwnTotpEnrolment (feature 005 FR-030): the secret, its provisioning address and the QR code,
 * shown exactly once and never cached. A ceremony request: not audited.
 */
class OwnTotpEnrolmentController extends Controller
{
    public function store(Request $request, SecondFactorEnrolmentService $enrolment): JsonResponse
    {
        return response()
            ->json(['data' => $enrolment->startTotp($request, RequestContext::requirePrincipal($request))], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ->header('Cache-Control', 'no-store');
    }
}
