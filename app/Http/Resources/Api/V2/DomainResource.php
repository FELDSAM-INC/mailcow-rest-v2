<?php

namespace App\Http\Resources\Api\V2;

use Illuminate\Http\JsonResponse;

/**
 * Contract `Domain` (and `DomainCreated` with its creation report), from the representation that
 * App\Services\Domains\DomainPresenter builds.
 */
final class DomainResource
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function response(array $data, int $status = 200): JsonResponse
    {
        return response()
            ->json(['data' => $data], $status, ['ETag' => (string) $data['etag']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  list<array<string, mixed>>  $data
     * @param  array{total: int, limit: int, offset: int}  $meta
     */
    public static function list(array $data, array $meta): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => $meta], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
