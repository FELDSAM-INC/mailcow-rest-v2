<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\CompleteSingleSignOnRequest;
use App\Http\Resources\Api\V2\SessionResource;
use App\Services\IdentityProvider\SingleSignOnService;
use Illuminate\Http\JsonResponse;

/**
 * completeSingleSignOn (FR-022 to FR-028): `201` with the new session.
 */
class SingleSignOnCompletionController extends Controller
{
    public function store(CompleteSingleSignOnRequest $request, SingleSignOnService $sso): JsonResponse
    {
        $sso->complete($request, (string) $request->validated('code'), (string) $request->validated('state'));

        return SessionResource::fromSession($request->session())
            ->response()
            ->setStatusCode(201)
            ->header('Location', '/api/v2/session');
    }
}
