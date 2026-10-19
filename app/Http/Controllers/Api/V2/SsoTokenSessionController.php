<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\SsoTokenSignInRequest;
use App\Http\Resources\Api\V2\SessionResource;
use App\Services\Auth\SsoTokenService;
use Illuminate\Http\JsonResponse;

/**
 * createSessionWithSsoToken (feature 007 FR-060 to FR-063): any browser session signs in with a
 * domain administrator's single sign-on token; `201` with the new session, like a password sign-in.
 */
class SsoTokenSessionController extends Controller
{
    public function store(SsoTokenSignInRequest $request, SsoTokenService $tokens): JsonResponse
    {
        $tokens->signIn($request, $request->token());

        return SessionResource::fromSession($request->session())
            ->response()
            ->setStatusCode(201)
            ->header('Location', '/api/v2/session');
    }
}
