<?php

namespace Tests\Unit\Services;

use App\Exceptions\ProblemException;
use App\Services\Auth\SignInThrottle;
use App\Support\Http\ProblemType;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * T035 (FR-007, R17): failure counting, the 0.5 s delay steps and the hard limit.
 */
class SignInThrottleTest extends TestCase
{
    private function throttle(): SignInThrottle
    {
        return $this->app->make(SignInThrottle::class);
    }

    public function test_hit_returns_the_previous_count(): void
    {
        $throttle = $this->throttle();
        $key = $throttle->passwordKey('alice@example.org', '192.0.2.1');

        $this->assertSame(0, $throttle->hit($key));
        $this->assertSame(1, $throttle->hit($key));
        $this->assertSame(2, $throttle->hit($key));
    }

    public function test_the_delay_grows_in_half_second_steps(): void
    {
        $this->assertSame(0.0, $this->throttle()->delayFor(0));
        $this->assertSame(0.5, $this->throttle()->delayFor(1));
        $this->assertSame(4.5, $this->throttle()->delayFor(9));
    }

    public function test_the_limit_throws_too_many_requests_with_retry_after(): void
    {
        config(['restv2.signin_max_failures' => 3]);
        $throttle = $this->throttle();
        $key = $throttle->passwordKey('alice@example.org', '192.0.2.1');

        for ($i = 0; $i < 3; $i++) {
            $throttle->assertNotThrottled($key);
            $throttle->hit($key);
        }

        try {
            $throttle->assertNotThrottled($key);
            $this->fail('The fourth attempt must be throttled.');
        } catch (ProblemException $e) {
            $this->assertSame(ProblemType::TooManyRequests, $e->type);
            $this->assertGreaterThanOrEqual(1, $e->extensions['retryAfter']);
            $this->assertLessThanOrEqual(900, $e->extensions['retryAfter']);
            $this->assertSame((string) $e->extensions['retryAfter'], $e->headers['Retry-After']);
        }
    }

    public function test_clear_resets_the_count(): void
    {
        config(['restv2.signin_max_failures' => 2]);
        $throttle = $this->throttle();
        $key = $throttle->passkeyKey('192.0.2.1');
        $throttle->hit($key);
        $throttle->hit($key);

        $throttle->clear($key);

        $throttle->assertNotThrottled($key);
        $this->assertSame(0, $throttle->hit($key));
    }

    public function test_the_keys_are_hashed_per_username_and_address(): void
    {
        $throttle = $this->throttle();
        $signin = $throttle->passwordKey('alice@example.org', '192.0.2.1');
        $passkey = $throttle->passkeyKey('192.0.2.1');

        $this->assertSame('signin:'.hash('sha256', 'alice@example.org|192.0.2.1'), $signin);
        $this->assertSame('passkey:'.hash('sha256', '192.0.2.1'), $passkey);
        $this->assertNotSame($signin, $throttle->passwordKey('alice@example.org', '192.0.2.2'));

        $throttle->hit($signin);
        $throttle->hit($passkey);

        $redis = Redis::connection('mailcow');
        $this->assertTrue((bool) $redis->exists('restv2:'.$signin));
        $this->assertTrue((bool) $redis->exists('restv2:'.$passkey));
    }
}
