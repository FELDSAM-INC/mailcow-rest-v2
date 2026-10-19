<?php

namespace App\Http\Resources\Api\V2;

use Illuminate\Http\JsonResponse;

/**
 * Contract `SpamPolicyEntry` responses (feature 006 FR-045): single entries with their `ETag` (and
 * `Location` when created), lists with `{data, meta}`.
 */
final class SpamPolicyEntryResource
{
    /**
     * @param  array<string, mixed>  $entry
     */
    public static function response(array $entry, int $status = 200, ?string $location = null): JsonResponse
    {
        $headers = ['ETag' => (string) $entry['etag']] + ($location === null ? [] : ['Location' => $location]);

        return response()->json(['data' => $entry], $status, $headers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}  $page
     */
    public static function list(array $page): JsonResponse
    {
        return response()->json($page, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
