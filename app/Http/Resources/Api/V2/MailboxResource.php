<?php

namespace App\Http\Resources\Api\V2;

use Illuminate\Http\JsonResponse;

/**
 * Contract `Mailbox` (and `MailboxCreated`, `MailboxRenamed`), from the representation that
 * App\Services\Mailboxes\MailboxReader builds; the `ETag` header equals `data.etag`.
 */
final class MailboxResource
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public static function response(array $data, int $status = 200, array $headers = []): JsonResponse
    {
        return response()->json(['data' => $data], $status, ['ETag' => (string) $data['etag']] + $headers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  list<array<string, mixed>>  $data
     * @param  array{total: int, limit: int, offset: int}  $meta
     */
    public static function list(array $data, array $meta): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => $meta], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function location(string $address): string
    {
        return '/api/v2/mailboxes/'.str_replace('%40', '@', rawurlencode($address));
    }
}
