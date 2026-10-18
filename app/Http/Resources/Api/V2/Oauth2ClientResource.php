<?php

namespace App\Http\Resources\Api\V2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contract `Oauth2Client`, or `Oauth2ClientWithSecret` when the creation or a secret regeneration
 * adds `clientSecret` (FR-042, FR-043, FR-046), as built by App\Services\Oauth2\Oauth2ClientService.
 *
 * @property array<string, mixed> $resource
 */
class Oauth2ClientResource extends JsonResource
{
    /**
     * The single-resource response with its `ETag`.
     *
     * @param  array<string, mixed>  $data
     */
    public static function single(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status, ['ETag' => (string) $data['etag']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
