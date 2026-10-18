<?php

namespace App\Clients\Development;

use App\Clients\Memcached\MemcachedFlusher;

/**
 * Development stand-in for the groupware's memcached (RESTV2_FAKE_OUTBOUND=y, APP_ENV=local).
 */
final class NullMemcachedFlusher implements MemcachedFlusher
{
    public function flush(): bool
    {
        return true;
    }
}
