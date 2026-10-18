<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Resources\Api\V2\Oauth2ClientResource;
use App\Models\Mailcow\Oauth2Client;
use App\Services\Oauth2\Oauth2ClientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * revokeOauth2ClientTokens (FR-047): removes the client's tokens and codes.
 */
class Oauth2ClientTokenRevocationController extends Controller
{
    public function store(Request $request, string $oauth2ClientId, Oauth2ClientService $clients): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        Gate::forUser($principal)->authorize('update', Oauth2Client::class);
        $clients->revokeTokens((int) $oauth2ClientId, $request->header('If-Match'), $principal);
        $row = $clients->find((int) $oauth2ClientId) ?? throw new NotFoundHttpException('The OAuth2 client does not exist.');

        return Oauth2ClientResource::single($clients->present($row));
    }
}
