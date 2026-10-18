<?php

namespace App\Services\Groupware;

use App\Clients\Memcached\MemcachedFlusher;
use Closure;

/**
 * The groupware cache flush after an incremental groupware directory refresh (legacy
 * flush_memcached() at the end of update_sogo_static_view(); feature 004 research R9). Services call
 * flush() after their commit. Inside deferred(), for example while a bulk action processes its
 * targets, the flushes requested are collected and made once at the end, also when the code throws.
 * Request-scoped; nothing happens when the groupware is disabled.
 */
class GroupwareCacheFlush
{
    private int $depth = 0;

    private bool $pending = false;

    public function __construct(private readonly MemcachedFlusher $flusher) {}

    public function flush(): void
    {
        if (config('restv2.skip_sogo')) {
            return;
        }

        if ($this->depth > 0) {
            $this->pending = true;

            return;
        }

        $this->flusher->flush();
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $code
     * @return T
     */
    public function deferred(Closure $code): mixed
    {
        $this->depth++;

        try {
            return $code();
        } finally {
            $this->depth--;

            if ($this->depth === 0 && $this->pending) {
                $this->pending = false;
                $this->flusher->flush();
            }
        }
    }
}
