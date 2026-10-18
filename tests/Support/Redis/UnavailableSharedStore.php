<?php

namespace Tests\Support\Redis;

use App\Clients\SharedStore\SharedStore;
use App\Clients\SharedStore\SharedStoreUnavailable;
use Illuminate\Contracts\Foundation\Application;

/**
 * mailcow's shared store as unreachable (feature 011 T047, T075, T098): every operation fails like
 * a refused connection.
 */
class UnavailableSharedStore extends SharedStore
{
    public static function bind(Application $app): void
    {
        $app->instance(SharedStore::class, new self($app->make('redis')));
    }

    public function run(callable $operation): mixed
    {
        throw new SharedStoreUnavailable('mailcow\'s shared store is unavailable: Connection refused');
    }
}
