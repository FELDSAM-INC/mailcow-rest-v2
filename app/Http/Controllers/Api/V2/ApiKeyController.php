<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ListApiKeysRequest;
use App\Http\Requests\Api\V2\StoreApiKeyRequest;
use App\Http\Resources\Api\V2\ApiKeyResource;
use App\Models\ApiKey;
use App\Services\ApiKeys\ApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * listApiKeys, getApiKey and createApiKey (FR-032–FR-037). Administrators only; creating needs a
 * signed-in administrator. Authorisation comes before the lookup, so other roles get `403` also
 * for unknown keys.
 */
class ApiKeyController extends Controller
{
    public function __construct(private readonly ApiKeyService $keys) {}

    public function index(ListApiKeysRequest $request): JsonResponse
    {
        $page = $this->keys->list($request->listQuery(), $request->filters());

        return response()->json([
            'data' => array_map(fn (mixed $key): array => (new ApiKeyResource($key))->toArray($request), $page['data']),
            'meta' => $page['meta'],
        ], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function show(Request $request, string $apiKeyId): JsonResponse
    {
        Gate::forUser(RequestContext::requirePrincipal($request))->authorize('view', ApiKey::class);

        $key = $this->keys->find($apiKeyId) ?? throw new NotFoundHttpException('The API key does not exist.');

        return (new ApiKeyResource($key))->withETag();
    }

    public function store(StoreApiKeyRequest $request): JsonResponse
    {
        [$key, $secret] = $this->keys->create(
            RequestContext::requirePrincipal($request),
            (string) $request->validated('name'),
            (string) $request->validated('ownerUsername'),
            (string) $request->validated('accessLevel'),
            $request->expiresAt(),
            $request->allowedNetworks(),
        );

        return (new ApiKeyResource($key))
            ->withSecret($secret)
            ->withETag(201)
            ->header('Location', '/api/v2/api-keys/'.$key->id);
    }
}
