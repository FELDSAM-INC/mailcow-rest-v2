<?php

namespace Tests\Feature\RateLimits;

use App\Services\RateLimits\RateLimitStore;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Feature 002 T014 (research D9): rate limits in mailcow's `RL_VALUE` hash, `"<value> / 1<s|m|h|d>"`
 * as legacy ratelimit('edit') writes them.
 */
class RateLimitStoreTest extends TestCase
{
    private function store(): RateLimitStore
    {
        return $this->app->make(RateLimitStore::class);
    }

    public function test_set_writes_the_legacy_format(): void
    {
        $this->store()->set('example.org', 10, 'hour');

        $this->assertSame('10 / 1h', Redis::connection('mailcow')->hget('RL_VALUE', 'example.org'));
    }

    public function test_get_parses_every_frame(): void
    {
        $redis = Redis::connection('mailcow');
        $redis->hset('RL_VALUE', 'a.example', '10 / 1h');
        $redis->hset('RL_VALUE', 'b.example', '5 / 1s');
        $redis->hset('RL_VALUE', 'c.example', '7 / 1d');
        $redis->hset('RL_VALUE', 'd.example', '3 / 1m');
        $redis->hset('RL_VALUE', 'bad.example', 'nonsense');
        $redis->hset('RL_VALUE', 'zero.example', '0 / 1h');
        $redis->hset('RL_VALUE', 'frame.example', '10 / 1w');

        $this->assertSame(['value' => 10, 'frame' => 'hour'], $this->store()->get('a.example'));
        $this->assertSame(['value' => 5, 'frame' => 'second'], $this->store()->get('b.example'));
        $this->assertSame(['value' => 7, 'frame' => 'day'], $this->store()->get('c.example'));
        $this->assertSame(['value' => 3, 'frame' => 'minute'], $this->store()->get('d.example'));
        $this->assertNull($this->store()->get('bad.example'));
        $this->assertNull($this->store()->get('zero.example'));
        $this->assertNull($this->store()->get('frame.example'));
        $this->assertNull($this->store()->get('missing.example'));

        $this->assertSame(
            ['a.example' => ['value' => 10, 'frame' => 'hour'], 'missing.example' => null],
            $this->store()->getMany(['a.example', 'missing.example']),
        );
    }

    public function test_delete_removes_the_field(): void
    {
        Redis::connection('mailcow')->hset('RL_VALUE', 'example.org', '10 / 1h');

        $this->store()->delete('example.org');

        $this->assertNull($this->store()->get('example.org'));
    }
}
