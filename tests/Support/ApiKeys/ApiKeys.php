<?php

namespace Tests\Support\ApiKeys;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * API key rows for tests (`restv2_api_keys`), inserted directly so that no creation audit record
 * is written. Used in Tests\TestCase subclasses.
 */
trait ApiKeys
{
    /**
     * Inserts a key and returns its ID, secret and prefix.
     *
     * @param  list<string>  $networks  normalised `address/prefix` entries
     * @return array{id: string, secret: string, prefix: string}
     */
    protected function apiKey(
        string $owner = 'admin',
        string $accessLevel = 'readWrite',
        array $networks = [],
        ?CarbonImmutable $expiresAt = null,
        ?CarbonImmutable $revokedAt = null,
        string $name = 'billing',
        string $createdBy = 'admin',
    ): array {
        $secret = 'mcv2_'.Str::random(40);
        $id = (string) Str::ulid();
        $now = CarbonImmutable::now('UTC');

        DB::table('restv2_api_keys')->insert([
            'id' => $id,
            'name' => $name,
            'prefix' => substr($secret, 0, 12),
            'secret_hash' => hash('sha256', $secret),
            'owner_username' => $owner,
            'access_level' => $accessLevel === 'readOnly' ? 'ro' : 'rw',
            'allowed_networks' => json_encode($networks),
            'expires_at' => $expiresAt?->utc()->format('Y-m-d H:i:s'),
            'created_by' => $createdBy,
            'revoked_at' => $revokedAt?->utc()->format('Y-m-d H:i:s'),
            'revoked_by' => $revokedAt === null ? null : 'admin',
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);

        return ['id' => $id, 'secret' => $secret, 'prefix' => substr($secret, 0, 12)];
    }

    /**
     * The `X-API-Key` header of a key.
     *
     * @param  array{secret: string}|string  $key
     * @return array<string, string>
     */
    protected function keyHeader(array|string $key): array
    {
        return ['X-API-Key' => is_string($key) ? $key : $key['secret']];
    }
}
