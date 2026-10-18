<?php

namespace App\Services\IdentityProvider;

use App\Auth\IdentityProviderType;
use App\Clients\Oidc\OidcTokens;
use App\Services\Config\MailcowConfig;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * The provider link of a single sign-on session (research R7): the provider's tokens and the time
 * of the last renewal, kept outside the session payload in `restv2:sso-link:<linkId>`, encrypted
 * with the application key, with mailcow's SESSION_LIFETIME as its idle lifetime. Never given to a
 * client, logged or audited.
 */
class SsoLinkStore
{
    private const PREFIX = 'sso-link:';

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly MailcowConfig $config,
    ) {}

    /**
     * Stores the tokens of a new single sign-on and returns the link's identifier.
     */
    public function create(OidcTokens $tokens, string $username, IdentityProviderType $type): string
    {
        $linkId = bin2hex(random_bytes(16));
        $this->put($linkId, [
            'accessToken' => $tokens->accessToken,
            'refreshToken' => $tokens->refreshToken,
            'expiresAt' => $tokens->expiresAt,
            'lastRenewedAt' => CarbonImmutable::now()->getTimestamp(),
            'username' => $username,
            'type' => $type->value,
        ]);

        return $linkId;
    }

    /**
     * @return array{accessToken: string, refreshToken: string|null, expiresAt: int|null, lastRenewedAt: int, username: string, type: string}|null
     */
    public function get(string $linkId): ?array
    {
        $raw = $this->connection()->get(self::PREFIX.$linkId);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $link = json_decode(Crypt::decryptString($raw), true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($link) || ! is_string($link['accessToken'] ?? null) || ! is_string($link['username'] ?? null) || ! is_string($link['type'] ?? null)) {
            return null;
        }

        return [
            'accessToken' => $link['accessToken'],
            'refreshToken' => is_string($link['refreshToken'] ?? null) ? $link['refreshToken'] : null,
            'expiresAt' => is_int($link['expiresAt'] ?? null) ? $link['expiresAt'] : null,
            'lastRenewedAt' => (int) ($link['lastRenewedAt'] ?? 0),
            'username' => $link['username'],
            'type' => $link['type'],
        ];
    }

    /**
     * @param  array{accessToken: string, refreshToken: string|null, expiresAt: int|null, lastRenewedAt: int, username: string, type: string}  $link
     */
    public function put(string $linkId, array $link): void
    {
        $this->connection()->setex(self::PREFIX.$linkId, $this->ttl(), Crypt::encryptString((string) json_encode($link, JSON_THROW_ON_ERROR)));
    }

    public function delete(string $linkId): void
    {
        $this->connection()->del(self::PREFIX.$linkId);
    }

    /**
     * Renews the idle lifetime, with the session's.
     */
    public function touch(string $linkId): void
    {
        $this->connection()->expire(self::PREFIX.$linkId, $this->ttl());
    }

    private function ttl(): int
    {
        return max(60, $this->config->sessionLifetime());
    }

    /**
     * @return Connection
     */
    private function connection(): object
    {
        return $this->redis->connection('restv2');
    }
}
