<?php

namespace App\Http\Resources\Api\V2;

use App\Clients\SharedStore\DkimKeyStore;
use App\Services\Dkim\DkimKeyChange;
use Illuminate\Http\JsonResponse;

/**
 * The contract's `DkimKeyChangeResult` (feature 010 research R3): the `DkimKey` entry after a
 * generation, import, copy or publication, plus `dnsPublication`. `201` with `Location` when the
 * name had no key, otherwise `200`; both with the key's `ETag`. Never a private key.
 */
final class DkimKeyChangeResource
{
    public static function response(DkimKeyChange $change): JsonResponse
    {
        $data = DkimKeyResource::present($change->entry) + ['dnsPublication' => $change->publication->toArray()];
        $response = response()->json(['data' => $data], $change->created ? 201 : 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($change->entry->key !== null) {
            $response->header('ETag', app(DkimKeyStore::class)->versionTag($change->entry->key));
        }

        return $change->created
            ? $response->header('Location', '/api/v2/dkim-keys/'.rawurlencode($change->entry->name->name))
            : $response;
    }
}
