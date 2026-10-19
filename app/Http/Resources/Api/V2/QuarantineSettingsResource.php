<?php

namespace App\Http\Resources\Api\V2;

use Illuminate\Http\JsonResponse;

/**
 * Contract `QuarantineSettings` responses (feature 006 FR-029, FR-034): every member for
 * administrators (`visibility: full`), the public subset for other roles (`visibility: public`),
 * with the `ETag` header.
 */
final class QuarantineSettingsResource
{
    /**
     * @param  array<string, mixed>  $settings
     */
    public static function response(array $settings): JsonResponse
    {
        return response()->json(['data' => $settings], 200, ['ETag' => (string) $settings['etag']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
