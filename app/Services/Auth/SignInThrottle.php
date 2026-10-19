<?php

namespace App\Services\Auth;

use App\Exceptions\ProblemException;
use App\Support\Clock\Sleeper;
use App\Support\Http\ProblemType;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;

/**
 * Sign-in throttling (FR-007, R17), shared by password, second-factor and passkey sign-in.
 *
 * Failures are counted in a fixed window (`RESTV2_SIGNIN_FAILURE_WINDOW`, from the first failure)
 * per sha256(username|address): password failures on `restv2:signin:<hash>`, which a verified
 * password resets, and second-factor failures on `restv2:secondfactor:<hash>`, which only a
 * completed sign-in resets (FR-024, orchestrator decision). Passkey failures are counted per
 * sha256(address) (`restv2:passkey:<hash>`). Each failure is answered 0.5 s × (earlier failures of
 * its count) later; after `RESTV2_SIGNIN_MAX_FAILURES` failures attempts are refused with `429`
 * without checking credentials.
 *
 * The window ends at the time Laravel's rate limiter records with the first failure, compared with
 * the application clock, so that it also ends when the Redis key outlives it.
 */
class SignInThrottle
{
    private readonly RateLimiter $limiter;

    private readonly Repository $store;

    public function __construct(CacheFactory $cache, private readonly Sleeper $sleeper)
    {
        $this->store = $cache->store('restv2');
        $this->limiter = new RateLimiter($this->store);
    }

    /**
     * @throws ProblemException too-many-requests, with `retryAfter` (at least 1) and `Retry-After`
     */
    public function assertNotThrottled(string $key): void
    {
        $this->expireIfDue($key);

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
        $this->expireIfDue($key);
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

    /**
     * The second-factor count of a username and address: not reset by a correct password, only by
     * a completed sign-in, so that repeating the password step gives no fresh second-factor guesses.
     */
    public function secondFactorKey(string $username, string $ip): string
    {
        return 'secondfactor:'.hash('sha256', $username.'|'.$ip);
    }

    public function passkeyKey(string $ip): string
    {
        return 'passkey:'.hash('sha256', $ip);
    }

    /**
     * Feature 007 (FR-062): failed single sign-on token sign-ins per client address.
     */
    public function ssoTokenKey(string $ip): string
    {
        return 'ssotoken:'.hash('sha256', $ip);
    }

    /**
     * Ends the window when its end has passed.
     */
    private function expireIfDue(string $key): void
    {
        $endsAt = $this->store->get($key.':timer');

        if (is_numeric($endsAt) && (int) $endsAt <= CarbonImmutable::now()->getTimestamp()) {
            $this->limiter->clear($key);
        }
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
