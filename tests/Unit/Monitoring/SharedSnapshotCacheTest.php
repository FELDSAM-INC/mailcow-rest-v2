<?php

namespace Tests\Unit\Monitoring;

use App\Services\Monitoring\SharedSnapshotCache;
use App\Services\Monitoring\Snapshot;
use Carbon\CarbonImmutable;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Feature 011 T010 (research R5, FR-006, SC-004): live data is collected once for all clients and
 * served while it is at most its maximum age old, judged by when it was measured.
 */
class SharedSnapshotCacheTest extends TestCase
{
    private int $loads = 0;

    private function cache(?Repository $repository = null): SharedSnapshotCache
    {
        return new SharedSnapshotCache($repository ?? Cache::store('restv2'));
    }

    private function loader(?CarbonImmutable $collectedAt = null): \Closure
    {
        return function () use ($collectedAt): Snapshot {
            $this->loads++;

            return new Snapshot(['load' => $this->loads], $collectedAt ?? CarbonImmutable::now('UTC'));
        };
    }

    public function test_a_fresh_snapshot_is_served_without_calling_the_loader(): void
    {
        $cache = $this->cache();
        $first = $cache->get('test', 5, 1.0, $this->loader());
        $this->travel(4)->seconds();
        $second = $cache->get('test', 5, 1.0, $this->loader());

        $this->assertSame(1, $this->loads);
        $this->assertSame(['load' => 1], $second->payload);
        $this->assertTrue($first->collectedAt->equalTo($second->collectedAt));
    }

    public function test_a_snapshot_older_than_the_maximum_age_calls_the_loader_once(): void
    {
        $cache = $this->cache();
        $cache->get('test', 5, 1.0, $this->loader());
        $this->travel(6)->seconds();

        $snapshot = $cache->get('test', 5, 1.0, $this->loader());

        $this->assertSame(2, $this->loads);
        $this->assertSame(['load' => 2], $snapshot->payload);
    }

    public function test_freshness_is_judged_by_the_measurement_time(): void
    {
        $cache = $this->cache();
        // The source had measured 8 seconds ago: served for only 2 more seconds of a 10 s age.
        $cache->get('host', 10, 1.0, $this->loader(CarbonImmutable::now('UTC')->subSeconds(8)));
        $this->travel(1)->seconds();
        $cache->get('host', 10, 1.0, $this->loader());
        $this->assertSame(1, $this->loads);

        $this->travel(2)->seconds();
        $cache->get('host', 10, 1.0, $this->loader());
        $this->assertSame(2, $this->loads);
    }

    public function test_a_snapshot_stored_by_another_request_while_waiting_for_the_lock_is_served(): void
    {
        $store = Cache::store('restv2')->getStore();
        $repository = new class($store) extends Repository
        {
            public int $reads = 0;

            /**
             * @param  string  $key
             */
            public function get($key, $default = null): mixed
            {
                $this->reads++;

                // The other request refreshed the snapshot while this one waited for the lock.
                if ($this->reads === 2) {
                    $this->put($key, ['payload' => ['load' => 'other'], 'collectedAt' => CarbonImmutable::now('UTC')->toIso8601ZuluString('microsecond')], 10);
                }

                return parent::get($key, $default);
            }
        };

        $snapshot = $this->cache($repository)->get('test', 5, 1.0, $this->loader());

        $this->assertSame(0, $this->loads, 'the loader ran although another request had just collected');
        $this->assertSame(['load' => 'other'], $snapshot->payload);
    }

    public function test_concurrent_callers_cause_one_loader_call(): void
    {
        $cache = $this->cache();
        $results = [];

        // Ten pollers in a row within the maximum age.
        for ($i = 0; $i < 10; $i++) {
            $results[] = $cache->get('test', 5, 1.0, $this->loader());
        }

        $this->assertSame(1, $this->loads);
        $this->assertCount(10, $results);
    }

    public function test_forget_invalidates(): void
    {
        $cache = $this->cache();
        $cache->get('test', 5, 1.0, $this->loader());
        $cache->forget('test');
        $cache->get('test', 5, 1.0, $this->loader());

        $this->assertSame(2, $this->loads);
    }

    public function test_the_stored_ttl_is_the_maximum_age_plus_five_seconds(): void
    {
        $this->cache()->get('mail-storage', 60, 1.0, $this->loader());

        $ttl = Redis::connection('restv2')->command('ttl', ['monitoring:mail-storage']);
        $this->assertSame(65, $ttl);
    }

    public function test_a_lock_wait_timeout_falls_back_to_a_direct_load(): void
    {
        $store = Cache::store('restv2')->getStore();
        $this->assertInstanceOf(LockProvider::class, $store);
        $lock = $store->lock('lock:monitoring:test', 10);
        $this->assertTrue($lock->get(), 'the test could not take the lock');

        try {
            $snapshot = $this->cache()->get('test', 5, 0.3, $this->loader());
        } finally {
            $lock->release();
        }

        $this->assertSame(1, $this->loads);
        $this->assertSame(['load' => 1], $snapshot->payload);
    }
}
