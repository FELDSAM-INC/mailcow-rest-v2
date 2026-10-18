<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\PasskeySignInRequest;
use App\Http\Resources\Api\V2\SessionResource;
use App\Services\Auth\PasskeySignInService;
use Illuminate\Http\JsonResponse;

/**
 * createSessionWithPasskey (FR-015–FR-018): `201` with the signed-in session.
 */
class PasskeyAssertionController extends Controller
{
    public function store(PasskeySignInRequest $request, PasskeySignInService $passkeys): JsonResponse
    {
        $passkeys->signIn($request, (array) $request->validated('credential'), $request->role());

        return SessionResource::fromSession($request->session())
            ->response()
            ->setStatusCode(201)
            ->header('Location', '/api/v2/session');
    }
}
