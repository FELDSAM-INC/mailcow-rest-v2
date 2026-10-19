<?php

namespace App\Http\Resources\Api\V2;

use Illuminate\Http\JsonResponse;

/**
 * Single-account responses of feature 007 (contract `AdminResponse`, `AdminCreatedResponse`,
 * `DomainAdminResponse`, `DomainAdminCreatedResponse`): the representation that
 * App\Services\Accounts\AccountRepresentation builds, with its version tag as `ETag` and, for a
 * creation, the `Location` of the new account.
 */
final class AccountResource
{
    /**
     * @param  array<string, mixed>  $account
     */
    public static function response(array $account, int $status = 200, ?string $location = null): JsonResponse
    {
        $response = response()
            ->json(['data' => $account], $status, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ->header('ETag', (string) $account['etag']);

        return $location === null ? $response : $response->header('Location', $location);
    }

    /**
     * @param  array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}  $page
     */
    public static function list(array $page): JsonResponse
    {
        return response()->json($page, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
