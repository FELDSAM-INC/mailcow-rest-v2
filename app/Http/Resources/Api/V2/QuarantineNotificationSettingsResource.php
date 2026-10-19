<?php

namespace App\Http\Resources\Api\V2;

use Illuminate\Http\JsonResponse;

/**
 * Contract `QuarantineNotificationSettings` responses (feature 006 FR-035), with the `ETag` header.
 */
final class QuarantineNotificationSettingsResource
{
    /**
     * @param  array{mailbox: string, frequency: string, category: string|null, etag: string}  $settings
     */
    public static function response(array $settings): JsonResponse
    {
        return response()->json(['data' => $settings], 200, ['ETag' => $settings['etag']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
