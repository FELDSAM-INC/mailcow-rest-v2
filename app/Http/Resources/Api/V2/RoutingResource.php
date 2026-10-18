<?php

namespace App\Http\Resources\Api\V2;

use Illuminate\Http\JsonResponse;

/**
 * Responses of feature 009, from the representations the routing services build (`Relayhost`,
 * `Transport`, `BccMap`, …): single resources with their `ETag` (and an optional `meta`, for
 * example the transports a write affected), and lists with `{data, meta}`.
 */
final class RoutingResource
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $meta
     */
    public static function response(array $data, int $status = 200, ?array $meta = null): JsonResponse
    {
        $body = ['data' => $data];

        if ($meta !== null) {
            $body['meta'] = $meta;
        }

        $headers = isset($data['etag']) ? ['ETag' => (string) $data['etag']] : [];

        return response()->json($body, $status, $headers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array{data: list<mixed>, meta: array{total: int, limit: int, offset: int}}  $page
     */
    public static function list(array $page): JsonResponse
    {
        return response()->json(['data' => $page['data'], 'meta' => $page['meta']], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function bulk(array $body): JsonResponse
    {
        return response()->json($body, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
