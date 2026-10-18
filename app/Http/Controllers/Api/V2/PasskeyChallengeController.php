<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\Auth\PasskeySignInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * createPasskeyChallenge (FR-015, FR-017): a single-use challenge for usernameless passkey
 * sign-in. A ceremony request: not audited.
 */
class PasskeyChallengeController extends Controller
{
    public function store(Request $request, PasskeySignInService $passkeys): JsonResponse
    {
        $challenge = $passkeys->issueChallenge($request);

        return response()->json(['data' => [
            'publicKey' => $challenge['publicKey'],
            'expiresAt' => $challenge['expiresAt']->utc()->format('Y-m-d\TH:i:s\Z'),
        ]], 200, [], JSON_UNESCAPED_SLASHES);
    }
}
