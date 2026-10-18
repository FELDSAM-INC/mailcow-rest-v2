<?php

namespace App\Services\Auth;

use App\Exceptions\ProblemException;
use App\Support\Clock\Sleeper;
use App\Support\Http\ProblemType;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Factory as CacheFactory;

/**
 * Sign-in throttling (FR-007, R17), shared by password, second-factor and passkey sign-in.
 *
 * Failures are counted in a fixed window (`RESTV2_SIGNIN_FAILURE_WINDOW`, from the first failure)
 * per sha256(username|address) (`restv2:signin:<hash>`) or, for passkeys, per sha256(address)
 * (`restv2:passkey:<hash>`). Each failure is answered 0.5 s × (earlier failures) later; after
 * `RESTV2_SIGNIN_MAX_FAILURES` failures attempts are refused with `429` without checking
 * credentials.
 */
class SignInThrottle
{
    private readonly RateLimiter $limiter;

    public function __construct(CacheFactory $cache, private readonly Sleeper $sleeper)
    {
        $this->limiter = new RateLimiter($cache->store('restv2'));
    }

    /**
     * @throws ProblemException too-many-requests, with `retryAfter` (at least 1) and `Retry-After`
     */
    public function assertNotThrottled(string $key): void
    {
        if (! $this->limiter->tooManyAttempts($key, $this->maxFailures())) {
            return;
        }

        $retryAfter = max(1, $this->limiter->availableIn($key));

        throw new ProblemException(
            ProblemType::TooManyRequests,
            null,
            ['retryAfter' => $retryAfter],
            ['Retry-After' => (string) $retryAfter],
        );
    }

    /**
     * Counts a failure and returns the number of failures before it.
     */
    public function hit(string $key): int
    {
        $previous = (int) $this->limiter->attempts($key);
        $this->limiter->hit($key, $this->window());

        return $previous;
    }

    /**
     * Seconds to wait before answering a failure that had $previousFailures before it.
     */
    public function delayFor(int $previousFailures): float
    {
        return 0.5 * max(0, $previousFailures);
    }

    /**
     * Waits the delay of a failure (the first failure is not delayed).
     */
    public function delay(int $previousFailures): void
    {
        $this->sleeper->sleepSeconds($this->delayFor($previousFailures));
    }

    public function clear(string $key): void
    {
        $this->limiter->clear($key);
    }

    public function passwordKey(string $username, string $ip): string
    {
        return 'signin:'.hash('sha256', $username.'|'.$ip);
    }

    public function passkeyKey(string $ip): string
    {
        return 'passkey:'.hash('sha256', $ip);
    }

    private function maxFailures(): int
    {
        return max(1, (int) config('restv2.signin_max_failures'));
    }

    private function window(): int
    {
        return max(1, (int) config('restv2.signin_failure_window'));
    }
}
