<?php

namespace App\Support\Database;

use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * MariaDB named locks (`GET_LOCK()` / `RELEASE_LOCK()`, docs/conventions.md "Uniqueness that
 * upstream enforces only in code"), created with feature 003 and reused by later features. A lock
 * belongs to the request's database session, so it also guards against other API processes and is
 * released when the process dies. Several locks are taken in sorted order, so that two requests
 * locking the same names never deadlock.
 */
class NamedLock
{
    public const DEFAULT_TIMEOUT = 5;

    /**
     * Runs the code while holding every named lock; the locks are released afterwards, also when
     * the code throws.
     *
     * @template T
     *
     * @param  list<string>  $names
     * @param  Closure(): T  $code
     * @return T
     *
     * @throws LockNotAcquired when a lock is not acquired within the timeout (seconds)
     */
    public function run(array $names, Closure $code, int $timeout = self::DEFAULT_TIMEOUT): mixed
    {
        $names = array_values(array_unique($names));
        sort($names);
        $held = [];

        try {
            foreach ($names as $name) {
                $this->acquire($name, $timeout);
                $held[] = $name;
            }

            return $code();
        } finally {
            foreach (array_reverse($held) as $name) {
                $this->release($name);
            }
        }
    }

    /**
     * @throws LockNotAcquired
     */
    public function acquire(string $name, int $timeout = self::DEFAULT_TIMEOUT): void
    {
        $result = DB::selectOne('SELECT GET_LOCK(?, ?) AS `acquired`', [$name, $timeout]);

        if ((int) ($result->acquired ?? 0) !== 1) {
            throw new LockNotAcquired("The lock {$name} was not acquired within {$timeout} seconds.");
        }
    }

    public function release(string $name): void
    {
        try {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS `released`', [$name]);
        } catch (Throwable) {
            // The session may be gone; MariaDB then released the lock itself.
        }
    }
}
