<?php

namespace App\Http\Resources\Api\V2;

use Illuminate\Http\JsonResponse;

/**
 * Contract `SpamScore` responses (feature 006 FR-039), with the `ETag` header.
 */
final class SpamScoreResource
{
    /**
     * @param  array<string, mixed>  $score
     */
    public static function response(array $score): JsonResponse
    {
        return response()->json(['data' => $score], 200, ['ETag' => (string) $score['etag']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
