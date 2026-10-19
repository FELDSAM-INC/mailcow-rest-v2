<?php

namespace App\Http\Resources\Api\V2;

use Illuminate\Http\JsonResponse;

/**
 * Contract `RateLimit` responses (feature 006 FR-050): single limits with their `ETag`, lists with
 * `{data, meta}`.
 */
final class RateLimitResource
{
    /**
     * @param  array<string, mixed>  $limit
     */
    public static function response(array $limit): JsonResponse
    {
        return response()->json(['data' => $limit], 200, ['ETag' => (string) $limit['etag']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}  $page
     */
    public static function list(array $page): JsonResponse
    {
        return response()->json($page, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
