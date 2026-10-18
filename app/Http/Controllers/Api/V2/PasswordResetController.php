<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\CheckPasswordResetTokenRequest;
use App\Http\Requests\Api\V2\CompletePasswordResetRequest;
use App\Http\Requests\Api\V2\PasswordResetWebAuthnChallengeRequest;
use App\Http\Requests\Api\V2\RequestPasswordResetRequest;
use App\Services\PasswordReset\PasswordResetService;
use Illuminate\Http\JsonResponse;

/**
 * requestPasswordReset, checkPasswordResetToken, createPasswordResetWebAuthnChallenge and
 * completePasswordReset (feature 005 FR-019–FR-026): browser sessions, signed in or not, with the
 * anti-forgery token. The acknowledgement of a request is the same for every address.
 */
class PasswordResetController extends Controller
{
    public function __construct(private readonly PasswordResetService $resets) {}

    public function store(RequestPasswordResetRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->resets->request($request, $request->address())]);
    }

    public function check(CheckPasswordResetTokenRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->resets->check($request->token())], 200, [], JSON_UNESCAPED_SLASHES);
    }

    public function webauthnChallenge(PasswordResetWebAuthnChallengeRequest $request): JsonResponse
    {
        $challenge = $this->resets->webauthnChallenge($request, $request->token());

        return response()->json(['data' => [
            'publicKey' => $challenge['publicKey'],
            'expiresAt' => $challenge['expiresAt']->format('Y-m-d\TH:i:s\Z'),
        ]], 200, [], JSON_UNESCAPED_SLASHES);
    }

    public function complete(CompletePasswordResetRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->resets->complete($request, $request->token(), $request->newPassword(), $request->secondFactor())]);
    }
}
