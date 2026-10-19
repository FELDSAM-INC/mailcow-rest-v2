<?php

namespace App\Services\ApiKeys;

use App\Auth\AccountKind;
use App\Auth\ApiKeyReference;
use App\Auth\AuthMethod;
use App\Auth\Principal;
use App\Auth\Role;
use App\Clients\Fail2Ban\Fail2BanReporter;
use App\Exceptions\ProblemException;
use App\Models\ApiKey;
use App\Models\Mailcow\Admin;
use App\Support\Http\ProblemType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Authenticates an `X-API-Key` value (FR-033, FR-038–FR-041, R19). In this order:
 *
 *  1. a value longer than 256 characters or not of the form `mcv2_` + 40 base62 characters, or a
 *     value whose SHA-256 matches no key → `api-key-invalid` (legacy `/api/v1` keys never match);
 *  2. a revoked key → `api-key-revoked`;
 *  3. an expired key → `api-key-expired`;
 *  4. an owner missing from mailcow's `admin` table, inactive, or created after the key was bound to
 *     it (feature 007 FR-036: `owner_created_at`) → `api-key-invalid`;
 *  5. a client address outside a non-empty list of allowed networks → `api-key-source-not-allowed`.
 *
 * Every refusal is published to mailcow's fail2ban channel as legacy reports an invalid API key.
 * A valid key records its last use, at most once a minute per address, and acts as its owner with
 * the owner's current role.
 */
class ApiKeyAuthenticator
{
    public const PATTERN = '/^mcv2_[A-Za-z0-9]{40}$/';

    /** Seconds within which a use from the same address is not recorded again. */
    private const LAST_USE_INTERVAL = 60;

    public function __construct(private readonly Fail2BanReporter $fail2ban) {}

    /**
     * @throws ProblemException the key outcome (FR-039)
     */
    public function authenticate(string $value, string $ip): Principal
    {
        $value = trim($value);
        $key = strlen($value) <= 256 && preg_match(self::PATTERN, $value) === 1
            ? ApiKey::query()->where('secret_hash', hash('sha256', $value))->first()
            : null;

        if ($key === null) {
            $this->refuse(ProblemType::ApiKeyInvalid, $ip);
        }

        if ($key->revoked_at !== null) {
            $this->refuse(ProblemType::ApiKeyRevoked, $ip);
        }

        if ($key->status() === 'expired') {
            $this->refuse(ProblemType::ApiKeyExpired, $ip);
        }

        $owner = Admin::query()->whereKey($key->owner_username)->toBase()->first(['username', 'superadmin', 'active', 'created']);

        // Feature 007 (FR-036): a key bound to its owner's creation time never acts for a later
        // account with the same username (a legacy deletion and re-creation).
        if ($owner === null || (int) $owner->active !== 1
            || ($key->owner_created_at !== null && $key->owner_created_at !== (string) $owner->created)) {
            $this->refuse(ProblemType::ApiKeyInvalid, $ip);
        }

        if ($key->allowed_networks !== [] && ! IpUtils::checkIp($ip, $key->allowed_networks)) {
            $this->refuse(ProblemType::ApiKeySourceNotAllowed, $ip);
        }

        $this->recordUse($key, $ip);

        return new Principal(
            username: (string) $owner->username,
            role: (int) $owner->superadmin === 1 ? Role::Admin : Role::DomainAdmin,
            accountKind: AccountKind::Admin,
            authMethod: AuthMethod::ApiKey,
            apiKey: new ApiKeyReference($key->id, $key->name, $key->prefix, $key->accessLevel()),
        );
    }

    /**
     * Records the last use when it is older than a minute or came from another address: one
     * conditional UPDATE, so that a busy key writes about once a minute.
     */
    private function recordUse(ApiKey $key, string $ip): void
    {
        $now = CarbonImmutable::now('UTC');

        DB::table('restv2_api_keys')
            ->where('id', $key->id)
            ->where(fn ($query) => $query
                ->whereNull('last_used_at')
                ->orWhere('last_used_at', '<=', $now->subSeconds(self::LAST_USE_INTERVAL)->format('Y-m-d H:i:s'))
                ->orWhereNull('last_used_from')
                ->orWhere('last_used_from', '!=', $ip))
            ->update(['last_used_at' => $now->format('Y-m-d H:i:s'), 'last_used_from' => $ip]);
    }

    private function refuse(ProblemType $type, string $ip): never
    {
        $this->fail2ban->invalidApiKey($ip);

        throw new ProblemException($type);
    }
}
