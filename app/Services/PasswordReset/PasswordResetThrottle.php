<?php

namespace App\Services\PasswordReset;

use App\Exceptions\ProblemException;
use App\Support\Http\ProblemType;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Factory as CacheFactory;

/**
 * The throttling of the password reset (feature 005 FR-026, research R6): requests, token checks
 * and completions are each limited per client address (`restv2:throttle:pwreset:<step>:<sha256(ip)>`,
 * `RESTV2_PW_RESET_MAX_ATTEMPTS` in `RESTV2_PW_RESET_WINDOW` seconds, 10 in 15 minutes by default).
 * Every attempt counts; above the limit the answer is `429` with `Retry-After`.
 */
class PasswordResetThrottle
{
    private readonly RateLimiter $limiter;

    public function __construct(CacheFactory $cache)
    {
        $this->limiter = new RateLimiter($cache->store('restv2'));
    }

    /**
     * Counts an attempt of the step, or refuses it when the address reached the limit.
     *
     * @param  'request'|'check'|'complete'  $step
     *
     * @throws ProblemException too-many-requests
     */
    public function attempt(string $step, string $ip): void
    {
        $key = 'throttle:pwreset:'.$step.':'.hash('sha256', $ip);
        $max = max(1, (int) config('restv2.pw_reset_max_attempts'));

        if ($this->limiter->tooManyAttempts($key, $max)) {
            $retryAfter = max(1, $this->limiter->availableIn($key));

            throw new ProblemException(ProblemType::TooManyRequests, null, ['retryAfter' => $retryAfter], ['Retry-After' => (string) $retryAfter]);
        }

        $this->limiter->hit($key, max(1, (int) config('restv2.pw_reset_window')));
    }
}
