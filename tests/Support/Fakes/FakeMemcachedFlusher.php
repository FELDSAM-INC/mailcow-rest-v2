<?php

namespace Tests\Support\Fakes;

use App\Clients\Memcached\MemcachedFlusher;

/**
 * Counts groupware cache flushes instead of contacting memcached (feature 002 T024).
 */
final class FakeMemcachedFlusher implements MemcachedFlusher
{
    private int $flushes = 0;

    public function flush(): bool
    {
        $this->flushes++;

        return true;
    }

    public function flushCount(): int
    {
        return $this->flushes;
    }
}
