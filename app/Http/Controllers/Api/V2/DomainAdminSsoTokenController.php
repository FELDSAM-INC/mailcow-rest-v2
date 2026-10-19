<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Policies\DomainAdminPolicy;
use App\Services\Auth\SsoTokenService;
use App\Support\Http\NoStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * issueDomainAdminSsoToken (feature 007 FR-057 to FR-059): administrators, with a session or a
 * read-write key. The only response that ever contains the token, never stored by caches.
 */
class DomainAdminSsoTokenController extends Controller
{
    public function store(Request $request, string $username, SsoTokenService $tokens, DomainAdminPolicy $policy): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $policy->authorize($principal);

        return NoStore::apply(response()->json(['data' => $tokens->issue($principal, $username)], 200, [], JSON_UNESCAPED_SLASHES));
    }
}
