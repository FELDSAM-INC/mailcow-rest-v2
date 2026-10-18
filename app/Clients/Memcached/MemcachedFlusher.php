<?php

namespace App\Clients\Memcached;

/**
 * Flushes the groupware's memcached after a groupware directory rebuild (legacy
 * flush_memcached()). Failures are logged and ignored, never thrown. Faked in tests.
 */
interface MemcachedFlusher
{
    public function flush(): bool;
}
