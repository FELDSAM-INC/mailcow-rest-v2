<?php

namespace App\Services\ApiKeys;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Models\ApiKey;
use App\Models\Mailcow\Admin;
use App\Rules\IpNetwork;
use App\Services\Audit\AuditLogger;
use App\Support\Http\ETag;
use App\Support\Http\ListQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * Administrator-managed API keys (FR-032–FR-037, R19), the only writer of `restv2_api_keys` apart
 * from the last-use update of ApiKeyAuthenticator:
 *
 *  - creation generates `mcv2_` + 40 base62 characters from the CSPRNG, stores only its SHA-256
 *    and its first 12 characters, normalises the networks and audits, in one transaction, and
 *    returns the secret once;
 *  - revocation is final and idempotent: an already revoked key is returned unchanged before
 *    `If-Match` is evaluated, and only the first revocation is audited;
 *  - the version tag covers the stored settings only, never the last use or the derived status.
 */
class ApiKeyService
{
    /** Contract sort value => column. */
    public const SORT_COLUMNS = [
        'createdAt' => 'created_at',
        'name' => 'name',
        'expiresAt' => 'expires_at',
        'lastUsedAt' => 'last_used_at',
    ];

    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Creates a key and returns it with its secret, which is never available again.
     *
     * @param  list<string>  $allowedNetworks  validated by App\Rules\IpNetwork
     * @return array{ApiKey, string}
     */
    public function create(
        Principal $actor,
        string $name,
        string $ownerUsername,
        string $accessLevel,
        ?CarbonImmutable $expiresAt,
        array $allowedNetworks,
    ): array {
        $secret = self::generateSecret();
        // The owner exists (App\Rules\EligibleKeyOwner); keep mailcow's spelling of the username.
        $owner = (string) (Admin::query()->whereKey($ownerUsername)->value('username') ?? $ownerUsername);
        $networks = self::normaliseNetworks($allowedNetworks);

        $key = DB::transaction(function () use ($actor, $name, $owner, $accessLevel, $expiresAt, $networks, $secret): ApiKey {
            $key = new ApiKey;
            $key->forceFill([
                'name' => $name,
                'prefix' => substr($secret, 0, 12),
                'secret_hash' => hash('sha256', $secret),
                'owner_username' => $owner,
                'access_level' => ApiKey::storedAccessLevel($accessLevel),
                'allowed_networks' => $networks,
                'expires_at' => $expiresAt?->utc()->startOfSecond(),
                'created_by' => $actor->username,
            ])->save();

            $this->audit->record(AuditEventType::ApiKeyCreation, true, $actor, self::target($key), $actor->authMethod->value, null, [
                'ownerUsername' => $owner,
                'accessLevel' => $key->accessLevel(),
                'expiresAt' => self::time($key->expires_at),
                'allowedNetworks' => $networks,
            ]);

            return $key;
        });

        return [$this->find($key->id) ?? $key, $secret];
    }

    /**
     * @param  array{ownerUsername?: string|null, status?: string|null, accessLevel?: string|null}  $filters
     * @return array{data: list<mixed>, meta: array{total: int, limit: int, offset: int}}
     */
    public function list(ListQuery $query, array $filters): array
    {
        $builder = ApiKey::query()->with('owner');

        if (($filters['ownerUsername'] ?? null) !== null) {
            $builder->where('owner_username', $filters['ownerUsername']);
        }

        match ($filters['status'] ?? null) {
            'active' => $builder->active(),
            'expired' => $builder->expired(),
            'revoked' => $builder->revoked(),
            default => null,
        };

        if (($filters['accessLevel'] ?? null) !== null) {
            $builder->where('access_level', ApiKey::storedAccessLevel($filters['accessLevel']));
        }

        $query->applySearch($builder, ['name', 'prefix', 'owner_username']);

        return $query->paginate($builder, null, 'id');
    }

    public function find(string $id): ?ApiKey
    {
        return ApiKey::query()->with('owner')->whereKey($id)->first();
    }

    /**
     * Revokes the key (FR-036): effective from the next request, final, idempotent.
     */
    public function revoke(Request $request, Principal $actor, string $id): ApiKey
    {
        $key = DB::transaction(function () use ($request, $actor, $id): ApiKey {
            /** @var ApiKey|null $key */
            $key = ApiKey::query()->whereKey($id)->lockForUpdate()->first();

            if ($key === null) {
                throw new NotFoundHttpException('The API key does not exist.');
            }

            // A retried revocation succeeds whatever tag it carries (FR-036).
            if ($key->revoked_at !== null) {
                return $key;
            }

            if (ETag::ifMatchFails($request, self::etag($key))) {
                throw new PreconditionFailedHttpException('The API key was changed since it was read.');
            }

            $key->forceFill([
                'revoked_at' => CarbonImmutable::now('UTC')->startOfSecond(),
                'revoked_by' => $actor->username,
            ])->save();

            $this->audit->record(AuditEventType::ApiKeyRevocation, true, $actor, self::target($key), $actor->authMethod->value, null, [
                'ownerUsername' => $key->owner_username,
            ]);

            return $key;
        });

        return $this->find($key->id) ?? $key;
    }

    /**
     * The version tag of the key's stored settings (R19): never the last use or the status.
     */
    public static function etag(ApiKey $key): string
    {
        return ETag::of([
            $key->id,
            $key->name,
            $key->owner_username,
            $key->access_level,
            $key->allowed_networks,
            self::time($key->expires_at),
            self::time($key->revoked_at),
            $key->revoked_by,
        ]);
    }

    /**
     * Networks in their canonical `address/prefix` form, duplicates removed, order kept.
     *
     * @param  list<string>  $networks
     * @return list<string>
     */
    public static function normaliseNetworks(array $networks): array
    {
        $normalised = [];

        foreach ($networks as $network) {
            $value = IpNetwork::normalize($network);

            if ($value !== null && ! in_array($value, $normalised, true)) {
                $normalised[] = $value;
            }
        }

        return $normalised;
    }

    /**
     * RFC 3339 in UTC, as the contract writes times.
     */
    public static function time(?CarbonImmutable $time): ?string
    {
        return $time?->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * `mcv2_` and 40 base62 characters from the CSPRNG (about 238 bits).
     */
    private static function generateSecret(): string
    {
        $secret = 'mcv2_';

        for ($i = 0; $i < 40; $i++) {
            $secret .= self::ALPHABET[random_int(0, 61)];
        }

        return $secret;
    }

    /**
     * @return array{type: string, id: string, name: string}
     */
    private static function target(ApiKey $key): array
    {
        return ['type' => 'apiKey', 'id' => $key->id, 'name' => $key->name];
    }
}
