<?php

namespace App\Http\Resources\Api\V2;

use Illuminate\Http\JsonResponse;

/**
 * Single-resource responses of feature 013 (`{"data": ...}`), with the `ETag` header of resources
 * that carry a version tag (`data.etag`) and optional `meta`.
 */
final class SettingsResource
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $meta
     * @param  array<string, string>  $headers
     */
    public static function response(array $data, int $status = 200, ?array $meta = null, array $headers = []): JsonResponse
    {
        if (isset($data['etag']) && is_string($data['etag'])) {
            $headers['ETag'] = $data['etag'];
        }

        $body = ['data' => $data] + ($meta === null ? [] : ['meta' => $meta]);

        return response()->json($body, $status, $headers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** `{"data": {"valid": true}}` of a validate-only request (FR-093). */
    public static function valid(): JsonResponse
    {
        return response()->json(['data' => ['valid' => true]]);
    }
}
