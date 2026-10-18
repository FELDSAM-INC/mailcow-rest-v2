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
 * session's idle lifetime on every request, and pruned of sessions that no longer exist when it is
 * read. Ending a session also ends what is tied to it (SessionEndListener, the webmail hand-off).
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
     * Records the session's current identifier under its handle and refreshes the entry's lifetime.
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
        $entries = $connection->command('hgetall', [$key]);
        $ended = 0;

        foreach (is_array($entries) ? $entries : [] as $handle => $sessionId) {
            $handle = (string) $handle;

            if ($handle === $exceptHandle) {
                continue;
            }

            if ($this->exists((string) $sessionId)) {
                $this->handler()->destroy((string) $sessionId);
                $ended++;
            }

            $this->ended($handle);
            $connection->command('hdel', [$key, $handle]);
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
