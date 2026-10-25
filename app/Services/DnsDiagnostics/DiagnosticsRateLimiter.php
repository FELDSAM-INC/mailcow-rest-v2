<?php

namespace App\Services\DnsDiagnostics;

use App\Auth\Principal;
use App\Support\Clock\Clock;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

/**
 * The run limit of DNS diagnostics (feature 010 FR-049, research R20): RESTV2_DNS_DIAGNOSTICS_PER_MINUTE
 * (10) runs per caller per fixed 60-second window, the caller being `user:<username>` for sessions
 * and `apiKey:<id>` for API keys, counted in `restv2:dns-diagnostics:<sha256(caller)>`. check()
 * runs before the name is resolved, hit() only for a run that starts, so that a name that is not
 * found does not count. Windows are measured on the injectable clock.
 */
class DiagnosticsRateLimiter
{
    private const WINDOW = 60;

    /**
     * Atomically reserves a slot only when the current window has capacity.
     */
    private const HIT = <<<'LUA'
        local now, window, limit = tonumber(ARGV[1]), tonumber(ARGV[2]), tonumber(ARGV[3])
        local reset = tonumber(redis.call('HGET', KEYS[1], 'resetAt') or '0')
        local count = tonumber(redis.call('HGET', KEYS[1], 'count') or '0')
        if reset <= now then
          count = 0
          reset = now + window
          redis.call('HSET', KEYS[1], 'resetAt', tostring(reset))
        end
        if count >= limit then
          return {0, count, tostring(reset)}
        end
        count = count + 1
        redis.call('HSET', KEYS[1], 'count', tostring(count))
        redis.call('EXPIRE', KEYS[1], window * 2)
        return {1, count, tostring(reset)}
        LUA;

    public function __construct(private readonly Clock $clock) {}

    public static function key(Principal $principal): string
    {
        return 'dns-diagnostics:'.hash('sha256', $principal->apiKey !== null ? 'apiKey:'.$principal->apiKey->id : 'user:'.$principal->username);
    }

    /**
     * The window before a run: whether one may start, with the header values.
     *
     * @return array{allowed: bool, limit: int, remaining: int, reset: int}
     */
    public function check(Principal $principal): array
    {
        $stored = Redis::connection('restv2')->hmget(self::key($principal), ['count', 'resetAt']);
        $now = $this->now();
        $reset = (float) ($stored[1] ?? 0);
        $count = $reset > $now ? (int) ($stored[0] ?? 0) : 0;
        $limit = self::limit();

        return [
            'allowed' => $count < $limit,
            'limit' => $limit,
            'remaining' => max(0, $limit - $count),
            'reset' => $reset > $now ? (int) ceil($reset - $now) : self::WINDOW,
        ];
    }

    /**
     * Reserves a run. Callers must check allowed even after a successful check().
     *
     * @return array{allowed: bool, limit: int, remaining: int, reset: int}
     */
    public function hit(Principal $principal): array
    {
        $now = $this->now();
        $limit = self::limit();
        $client = Redis::connection('restv2')->client();
        $answer = $client instanceof \Redis ? $client->eval(self::HIT, [self::key($principal), (string) $now, (string) self::WINDOW, (string) $limit], 1) : false;

        if (! is_array($answer) || count($answer) !== 3) {
            throw new RuntimeException('The DNS diagnostics quota could not be reserved.');
        }

        $count = (int) $answer[1];
        $reset = (float) $answer[2];

        return ['allowed' => (int) $answer[0] === 1, 'limit' => $limit, 'remaining' => max(0, $limit - $count), 'reset' => max(0, (int) ceil($reset - $now))];
    }

    private static function limit(): int
    {
        return max(1, (int) config('restv2.dns.runs_per_minute', 10));
    }

    private function now(): float
    {
        return $this->clock->now()->getTimestampMs() / 1000;
    }
}
