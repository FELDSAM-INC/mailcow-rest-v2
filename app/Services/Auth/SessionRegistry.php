<?php

namespace App\Services\Auth;

use App\Auth\AccountKind;
use App\Services\Config\MailcowConfig;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Session\SessionManager as LaravelSessionManager;
use SessionHandlerInterface;

/**
 * The sessions of this API per account (feature 005 research R2), so that a password change ends
 * the account's other sessions and a completed reset all of them (FR-011, FR-025).
 *
 * Every signed-in session has a stable handle (a ULID in its data, `restv2.handle`) that survives
 * identifier renewals. `restv2:account-sessions:<accountKind>:<username>` is a Redis hash
 * `handle → current session ID`, written at sign-in and after every renewal, refreshed with the
 * session's idle lifetime on every request. This mapping is authoritative: a stale in-flight
 * request may save a deleted payload, but cannot recreate a revoked mapping. Ending a session
 * also ends what is tied to it (SessionEndListener, the webmail hand-off).
 */
class SessionRegistry
{
    public const LISTENER_TAG = 'restv2.session-listeners';

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly MailcowConfig $config,
        private readonly LaravelSessionManager $sessions,
    ) {}

    /**
     * Registers a newly authenticated session. Existing sessions must use refresh() or move().
     */
    public function register(AccountKind $kind, string $username, string $handle, string $sessionId): void
    {
        $key = self::key($kind, $username);
        $lifetime = $this->config->sessionLifetime();
        $connection = $this->redis->connection('restv2');

        $connection->command('hset', [$key, $handle, $sessionId]);
        $connection->command('expire', [$key, $lifetime]);

        foreach ($this->listeners() as $listener) {
            $listener->sessionTouched($handle, $lifetime);
        }
    }

    public function forget(AccountKind $kind, string $username, string $handle): void
    {
        $this->redis->connection('restv2')->command('hdel', [self::key($kind, $username), $handle]);
    }

    public function refresh(AccountKind $kind, string $username, string $handle, string $sessionId): bool
    {
        return $this->move($kind, $username, $handle, $sessionId, $sessionId);
    }

    /**
     * Atomically moves an existing mapping, or refreshes it when the identifier is unchanged.
     * A concurrent revocation or renewal must never be overwritten by a stale request.
     */
    public function move(AccountKind $kind, string $username, string $handle, string $previousId, string $sessionId, ?string $newUsername = null): bool
    {
        $script = <<<'LUA'
            if redis.call('HGET', KEYS[1], ARGV[1]) ~= ARGV[2] then
              return 0
            end
            if KEYS[1] ~= KEYS[2] then
              redis.call('HDEL', KEYS[1], ARGV[1])
            end
            redis.call('HSET', KEYS[2], ARGV[1], ARGV[3])
            redis.call('EXPIRE', KEYS[2], ARGV[4])
            return 1
            LUA;
        $lifetime = $this->config->sessionLifetime();
        $updated = $this->redis->connection('restv2')->command('eval', [$script, [
            self::key($kind, $username), self::key($kind, $newUsername ?? $username),
            $handle, $previousId, $sessionId, $lifetime,
        ], 2]);

        if ((int) $updated !== 1) {
            return false;
        }

        foreach ($this->listeners() as $listener) {
            $listener->sessionTouched($handle, $lifetime);
        }

        return true;
    }

    /**
     * The current identifier of the session with this handle, or null.
     */
    public function sessionId(AccountKind $kind, string $username, string $handle): ?string
    {
        $id = $this->redis->connection('restv2')->command('hget', [self::key($kind, $username), $handle]);

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Whether the session with this handle still exists in the session store.
     */
    public function isAlive(AccountKind $kind, string $username, string $handle): bool
    {
        $id = $this->sessionId($kind, $username, $handle);

        return $id !== null && $this->exists($id);
    }

    /**
     * Ends every session of the account except the one with $exceptHandle, together with what is
     * tied to it, and returns the number of sessions that were still alive.
     */
    public function endAll(AccountKind $kind, string $username, ?string $exceptHandle = null): int
    {
        $key = self::key($kind, $username);
        $connection = $this->redis->connection('restv2');
        // Remove authorization before touching payloads. A concurrent renewal either precedes
        // this operation and is included, or follows it and fails its compare-and-swap.
        $script = <<<'LUA'
            local entries = redis.call('HGETALL', KEYS[1])
            local ended = {}
            for i = 1, #entries, 2 do
              if entries[i] ~= ARGV[1] then
                redis.call('HDEL', KEYS[1], entries[i])
                table.insert(ended, entries[i])
                table.insert(ended, entries[i + 1])
              end
            end
            return ended
            LUA;
        $entries = $connection->command('eval', [$script, [$key, $exceptHandle ?? ''], 1]);
        $entries = is_array($entries) ? array_values($entries) : [];
        $ended = 0;

        for ($i = 0; $i < count($entries); $i += 2) {
            $handle = (string) $entries[$i];
            $sessionId = (string) $entries[$i + 1];

            if ($this->exists((string) $sessionId)) {
                $this->handler()->destroy((string) $sessionId);
                $ended++;
            }

            $this->ended($handle);
        }

        return $ended;
    }

    /**
     * Notifies the listeners that the session with this handle ended.
     */
    public function ended(string $handle): void
    {
        foreach ($this->listeners() as $listener) {
            $listener->sessionEnded($handle);
        }
    }

    public static function key(AccountKind $kind, string $username): string
    {
        return 'account-sessions:'.$kind->value.':'.$username;
    }

    private function exists(string $sessionId): bool
    {
        return $sessionId !== '' && $this->handler()->read($sessionId) !== '';
    }

    private function handler(): SessionHandlerInterface
    {
        return $this->sessions->driver()->getHandler();
    }

    /**
     * @return iterable<SessionEndListener>
     */
    private function listeners(): iterable
    {
        /** @var iterable<SessionEndListener> */
        return app()->tagged(self::LISTENER_TAG);
    }
}
