<?php

namespace App\Services\Monitoring;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

/**
 * The shared collection cache of feature 011 (research R5, FR-006, SC-004): live data is stored in
 * the `restv2` cache store (Redis, `restv2:monitoring:<key>`) with the time it was measured, and
 * served while it is at most its maximum age old. A stale entry is refreshed under the lock
 * `restv2:lock:monitoring:<key>`, so that concurrent pollers cause one collection: after waiting
 * for the lock, an entry another request stored meanwhile is served. When the wait times out, the
 * loader runs without the lock (degraded but correct). Entries expire `maxAge + 5` seconds after
 * they were stored.
 */
class SharedSnapshotCache
{
    private const PREFIX = 'monitoring:';

    public function __construct(private readonly Repository $cache) {}

    /**
     * @param  callable(): Snapshot  $loader  measures the data; runs only when no fresh entry exists
     * @param  float  $lockWait  seconds to wait for another request's collection (whole seconds, at least 1)
     */
    public function get(string $key, int $maxAge, float $lockWait, callable $loader): Snapshot
    {
        $fresh = $this->fresh($key, $maxAge);

        if ($fresh !== null) {
            return $fresh;
        }

        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            return $this->load($key, $maxAge, $loader);
        }

        // The lock outlives a collection bounded by DOCKER_TIMEOUT (60 s) by 5 seconds.
        $lock = $store->lock('lock:'.self::PREFIX.$key, 65);

        try {
            return $lock->block(max(1, (int) ceil($lockWait)), fn (): Snapshot => $this->fresh($key, $maxAge) ?? $this->load($key, $maxAge, $loader));
        } catch (LockTimeoutException) {
            return $this->load($key, $maxAge, $loader);
        }
    }

    public function forget(string $key): void
    {
        $this->cache->forget(self::PREFIX.$key);
    }

    private function fresh(string $key, int $maxAge): ?Snapshot
    {
        $entry = $this->cache->get(self::PREFIX.$key);

        if (! is_array($entry) || ! array_key_exists('payload', $entry) || ! is_string($entry['collectedAt'] ?? null)) {
            return null;
        }

        try {
            $snapshot = new Snapshot($entry['payload'], CarbonImmutable::parse($entry['collectedAt'])->utc());
        } catch (Throwable) {
            return null;
        }

        return $snapshot->age() <= $maxAge ? $snapshot : null;
    }

    /**
     * @param  callable(): Snapshot  $loader
     */
    private function load(string $key, int $maxAge, callable $loader): Snapshot
    {
        $snapshot = $loader();

        $this->cache->put(self::PREFIX.$key, [
            'payload' => $snapshot->payload,
            'collectedAt' => $snapshot->collectedAt->utc()->toIso8601ZuluString('microsecond'),
        ], $maxAge + 5);

        return $snapshot;
    }
}
