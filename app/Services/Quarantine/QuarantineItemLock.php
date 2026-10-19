<?php

namespace App\Services\Quarantine;

use App\Exceptions\ProblemException;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Throwable;

/**
 * The per-item lock that makes a quarantine item released, learned or deleted by at most one
 * request of this API (feature 006 research R4, FR-026): a Laravel cache lock on the `restv2` store
 * (key `restv2:quarantine-item:<id>`, an owner token, `SET NX PX`, released by compare-and-delete),
 * taken without waiting. Its TTL (`restv2.quarantine.lock_ttl`, 300 s) exceeds the worst case of a
 * release (delivery, learning and fuzzy hashes, 30 s each), so it never expires while its holder
 * runs, and it expires when the PHP worker dies. Redis errors answer `503 temporary-failure`
 * (`sharedStore`): nothing is done without the lock.
 */
class QuarantineItemLock
{
    public function __construct(private readonly CacheFactory $cache) {}

    public static function key(int $id): string
    {
        return 'quarantine-item:'.$id;
    }

    /**
     * The held lock, or null when another request holds it.
     *
     * @throws ProblemException `temporary-failure` (`sharedStore`)
     */
    public function acquire(int $id): ?Lock
    {
        try {
            $store = $this->cache->store('restv2')->getStore();

            if (! $store instanceof LockProvider) {
                throw new \LogicException('The restv2 cache store cannot lock.');
            }

            $lock = $store->lock(self::key($id), max(1, (int) config('restv2.quarantine.lock_ttl', 300)));

            return $lock->get() ? $lock : null;
        } catch (ProblemException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            throw ProblemException::temporaryFailure('sharedStore', null, 'This API\'s shared store is unavailable, so the item cannot be locked. Nothing was changed; retry later.');
        }
    }

    public static function release(Lock $lock): void
    {
        try {
            $lock->release();
        } catch (Throwable) {
            // The lock expires with its TTL.
        }
    }
}
