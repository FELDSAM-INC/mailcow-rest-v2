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

/**
 * regenerateOauth2ClientSecret (FR-046): a new secret, returned only in this response.
 */
class Oauth2ClientSecretRegenerationController extends Controller
{
    public function store(Request $request, string $oauth2ClientId, Oauth2ClientService $clients): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        Gate::forUser($principal)->authorize('update', Oauth2Client::class);
        $result = $clients->regenerateSecret((int) $oauth2ClientId, $request->header('If-Match'), $principal);

        return Oauth2ClientResource::single($clients->present($result['row']) + ['clientSecret' => $result['secret']]);
    }
}
