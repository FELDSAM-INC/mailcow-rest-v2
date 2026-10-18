<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ListOauth2ClientsRequest;
use App\Http\Requests\Api\V2\StoreOauth2ClientRequest;
use App\Http\Requests\Api\V2\UpdateOauth2ClientRequest;
use App\Http\Resources\Api\V2\Oauth2ClientResource;
use App\Models\Mailcow\Oauth2Client;
use App\Services\Oauth2\Oauth2ClientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * OAuth2 clients (FR-041 to FR-048): administrators only, read-only keys may read. The role is
 * checked before the client is looked up. The secret appears only in the creation response.
 */
class Oauth2ClientController extends Controller
{
    public function __construct(private readonly Oauth2ClientService $clients) {}

    public function index(ListOauth2ClientsRequest $request): JsonResponse
    {
        return response()->json($this->clients->list($request->listQuery(), $request->hasActiveTokens()), 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function show(Request $request, string $oauth2ClientId): JsonResponse
    {
        Gate::forUser(RequestContext::requirePrincipal($request))->authorize('view', Oauth2Client::class);
        $row = $this->clients->find((int) $oauth2ClientId) ?? throw new NotFoundHttpException('The OAuth2 client does not exist.');

        return Oauth2ClientResource::single($this->clients->present($row));
    }

    public function store(StoreOauth2ClientRequest $request): JsonResponse
    {
        $created = $this->clients->create($request->redirectUris(), RequestContext::requirePrincipal($request));
        $data = $this->clients->present($created['row']) + ['clientSecret' => $created['secret']];

        return Oauth2ClientResource::single($data, 201)->header('Location', '/api/v2/oauth2-clients/'.$data['id']);
    }

    public function update(UpdateOauth2ClientRequest $request, string $oauth2ClientId): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $id = (int) $oauth2ClientId;
        $row = $request->hasRedirectUris()
            ? $this->clients->updateRedirectUris($id, $request->redirectUris(), $request->header('If-Match'), $principal)
            : ($this->clients->find($id) ?? throw new NotFoundHttpException('The OAuth2 client does not exist.'));

        return Oauth2ClientResource::single($this->clients->present($row));
    }

    public function destroy(Request $request, string $oauth2ClientId): Response
    {
        $principal = RequestContext::requirePrincipal($request);
        Gate::forUser($principal)->authorize('delete', Oauth2Client::class);
        $this->clients->delete((int) $oauth2ClientId, $request->header('If-Match'), $principal);

        return response()->noContent();
    }
}
