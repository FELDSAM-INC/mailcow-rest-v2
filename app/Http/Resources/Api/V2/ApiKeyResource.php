<?php

namespace App\Http\Resources\Api\V2;

use App\Models\ApiKey;
use App\Services\ApiKeys\ApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contract `ApiKey` (FR-034, FR-035), or `ApiKeyCreated` with the secret right after creation.
 * The owner's role and state are read from mailcow's `admin` table (null role once deleted).
 *
 * @property ApiKey $resource
 */
class ApiKeyResource extends JsonResource
{
    private ?string $secret = null;

    /**
     * Includes the secret: only the creation response does (FR-034).
     */
    public function withSecret(string $secret): self
    {
        $this->secret = $secret;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $key = $this->resource;
        $owner = $key->owner;

        $data = [
            'id' => $key->id,
            'name' => $key->name,
            'prefix' => $key->prefix,
            'owner' => [
                'username' => $key->owner_username,
                'role' => $owner === null ? null : ($owner->superadmin ? 'admin' : 'domainAdmin'),
                'active' => $owner !== null && $owner->active,
            ],
            'accessLevel' => $key->accessLevel(),
            'allowedNetworks' => $key->allowed_networks,
            'expiresAt' => ApiKeyService::time($key->expires_at),
            'status' => $key->status(),
            'createdAt' => ApiKeyService::time($key->created_at),
            'createdBy' => ['username' => $key->created_by],
            'lastUsedAt' => ApiKeyService::time($key->last_used_at),
            'lastUsedFrom' => $key->last_used_from,
            'revokedAt' => ApiKeyService::time($key->revoked_at),
            'revokedBy' => $key->revoked_by === null ? null : ['username' => $key->revoked_by],
            'etag' => ApiKeyService::etag($key),
        ];

        if ($this->secret !== null) {
            $data['secret'] = $this->secret;
        }

        return $data;
    }

    /**
     * The single-resource response with its `ETag`.
     */
    public function withETag(int $status = 200): JsonResponse
    {
        return $this->response()->setStatusCode($status)->header('ETag', ApiKeyService::etag($this->resource));
    }
}
