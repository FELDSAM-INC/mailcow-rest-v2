<?php

namespace App\Http\Resources\Api\V2;

use App\Clients\SharedStore\DkimKeyStore;
use App\Services\Dkim\DkimEntry;
use App\Services\Dkim\DkimPublicKey;
use Illuminate\Http\JsonResponse;

/**
 * The contract's `DkimKey` (feature 010 FR-007): the name in ASCII and readable form, its kind and
 * state, the stored key without its private part (selector, exact length, public key, the DNS
 * record as one text and in 255-character parts, completeness) and its version tag. Never a
 * private key.
 */
final class DkimKeyResource
{
    /**
     * @return array<string, mixed>
     */
    public static function present(DkimEntry $entry): array
    {
        $key = $entry->key;

        return [
            'name' => $entry->name->name,
            'displayName' => $entry->name->displayName,
            'kind' => $entry->name->kind,
            'targetDomain' => $entry->name->targetDomain,
            'state' => $entry->state(),
            'key' => $key === null ? null : self::details($entry),
            'etag' => $key === null ? null : app(DkimKeyStore::class)->versionTag($key),
        ];
    }

    public static function response(DkimEntry $entry, int $status = 200): JsonResponse
    {
        $response = response()->json(['data' => self::present($entry)], $status, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $entry->key === null ? $response : $response->header('ETag', app(DkimKeyStore::class)->versionTag($entry->key));
    }

    /**
     * @param  list<DkimEntry>  $entries
     * @param  array{total: int, limit: int, offset: int}  $meta
     */
    public static function list(array $entries, array $meta): JsonResponse
    {
        return response()->json(['data' => array_map(self::present(...), $entries), 'meta' => $meta], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, mixed>
     */
    private static function details(DkimEntry $entry): array
    {
        $key = $entry->key;
        assert($key !== null);
        $public = $key->publicKey === null ? null : new DkimPublicKey($key->publicKey);
        $missing = array_keys(array_filter([
            'publicKey' => $key->publicKey === null,
            'selector' => $key->selector === null,
            'privateKey' => ! $key->hasPrivateKey,
        ]));

        return [
            'selector' => $key->selector,
            'keyLength' => $public?->bits(),
            'publicKey' => $key->publicKey,
            'record' => $public === null || $key->selector === null ? null : [
                'name' => $key->selector.'._domainkey.'.$entry->name->name,
                'type' => 'TXT',
                'value' => $public->recordValue(),
                'valueParts' => $public->recordParts(),
            ],
            'complete' => $missing === [],
            'missingParts' => $missing,
        ];
    }
}
