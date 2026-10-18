<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\Auth\SecondFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * createSecondFactorWebAuthnChallenge (FR-021, R9): a single-use WebAuthn challenge bound to the
 * pending sign-in. A ceremony request: not audited.
 */
class SecondFactorChallengeController extends Controller
{
    public function store(Request $request, SecondFactorService $secondFactors): JsonResponse
    {
        $challenge = $secondFactors->issueWebAuthnChallenge($request);

        return response()->json(['data' => [
            'publicKey' => $challenge['publicKey'],
            'expiresAt' => $challenge['expiresAt']->utc()->format('Y-m-d\TH:i:s\Z'),
        ]], 200, [], JSON_UNESCAPED_SLASHES);
    }
}
