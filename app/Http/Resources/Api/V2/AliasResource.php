<?php

namespace App\Http\Resources\Api\V2;

use Illuminate\Http\JsonResponse;

/**
 * Contract `Alias` responses, from the representation App\Services\Aliases\AliasPresenter builds:
 * single resources with their `ETag`, lists with `{data, meta}`.
 */
final class AliasResource
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function response(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status, ['ETag' => (string) $data['etag']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
