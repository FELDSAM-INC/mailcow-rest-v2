<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Resources\Api\V2\ApiKeyResource;
use App\Models\ApiKey;
use App\Services\ApiKeys\ApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * revokeApiKey (FR-036, FR-037): a signed-in administrator revokes a key. Final and idempotent.
 */
class ApiKeyRevocationController extends Controller
{
    public function __construct(private readonly ApiKeyService $keys) {}

    public function store(Request $request, string $apiKeyId): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        Gate::forUser($principal)->authorize('revoke', ApiKey::class);

        return (new ApiKeyResource($this->keys->revoke($request, $principal, $apiKeyId)))->withETag();
    }
}
