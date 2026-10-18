<?php

namespace App\Http\Resources\Api\V2;

use App\Support\Http\NoStore;
use Illuminate\Http\JsonResponse;

/**
 * The contract's `DkimPrivateKey` (feature 010 FR-026): the name, its selector and the private key
 * exactly as stored, with `Cache-Control: no-store`. The only response that ever carries a private
 * key.
 */
final class DkimPrivateKeyResource
{
    /**
     * @param  array{name: string, selector: string, privateKey: string}  $export
     */
    public static function response(#[\SensitiveParameter] array $export): JsonResponse
    {
        return NoStore::apply(response()->json(['data' => $export], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
