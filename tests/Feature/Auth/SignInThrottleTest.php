<?php

namespace Tests\Feature\Auth;

use App\Support\Hashing\LegacyPasswordVerifier;
use Tests\Support\Auth\AuthRequests;
use Tests\Support\Hashing\SpyPasswordVerifier;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\Support\Redis\ChannelSpy;
use Tests\TestCase;

/**
 * T056 (FR-007, AS-7, R17, R23): failed password sign-ins are delayed in 0.5 s steps per username
 * and client address, and refused with 429 after the limit, without checking the password.
 */
class SignInThrottleTest extends TestCase
{
    use AuthRequests;

    private const PASSWORD = MailcowFactory::PASSWORD;

    private const IP = '192.0.2.10';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withServerVariables(['REMOTE_ADDR' => self::IP]);
        $this->mailcow()->mailboxWithDomain('alice@example.org');
    }

    private function failSignIns(int $times, string $username = 'alice@example.org'): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->passwordSignIn($username, 'wrong')->assertUnauthorized();
        }
    }

    public function test_failures_are_delayed_progressively(): void
    {
        $this->failSignIns(10);

        $this->assertSame([0.0, 0.5, 1.0, 1.5, 2.0, 2.5, 3.0, 3.5, 4.0, 4.5], $this->sleeper()->slept);
    }

    public function test_the_eleventh_attempt_is_refused_without_checking_the_password(): void
    {
        $this->failSignIns(10);
        $spy = new SpyPasswordVerifier;
        $this->app->instance(LegacyPasswordVerifier::class, $spy);
        $channel = ChannelSpy::fail2ban();

        $response = $this->passwordSignIn('alice@example.org', self::PASSWORD)->assertStatus(429);

        $response->assertJsonPath('type', '/api/v2/problems/too-many-requests');
        $retryAfter = $response->json('retryAfter');
        $this->assertIsInt($retryAfter);
        $this->assertGreaterThanOrEqual(1, $retryAfter);
        $this->assertLessThanOrEqual(900, $retryAfter);
        $response->assertHeader('Retry-After', (string) $retryAfter);
        $this->assertSame([], $spy->verifiedHashes);

        // Still published and audited.
        $channel->assertPublished('mailcow UI: Invalid password for alice@example.org by '.self::IP);
        $records = $this->auditRecords('signIn');
        $this->assertCount(11, $records);
        $this->assertSame('throttled', $records[10]->failure_reason);
        $this->assertSame('alice@example.org', $records[10]->actor_username);

        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'anonymous');
    }

    public function test_another_address_or_username_is_unaffected(): void
    {
        $this->mailcow()->mailboxWithDomain('bob@example.org');
        $this->failSignIns(10);

        $this->passwordSignIn('bob@example.org')->assertCreated();

        $this->forgetCookies();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11']);
        $this->passwordSignIn('alice@example.org')->assertCreated();
    }

    public function test_a_spoofed_forwarded_for_header_is_ignored(): void
    {
        $channel = ChannelSpy::fail2ban();

        for ($i = 0; $i < 10; $i++) {
            $this->passwordSignIn('alice@example.org', 'wrong', null, ['X-Forwarded-For' => '203.0.113.'.$i])->assertUnauthorized();
        }

        $this->passwordSignIn('alice@example.org', self::PASSWORD, null, ['X-Forwarded-For' => '203.0.113.99'])->assertStatus(429);

        $messages = $channel->messages(11);
        $this->assertCount(11, $messages);
        $this->assertSame(['mailcow UI: Invalid password for alice@example.org by '.self::IP], array_values(array_unique($messages)));
        $this->assertSame([self::IP], array_values(array_unique(array_column($this->auditRecords('signIn'), 'client_address'))));
    }

    public function test_a_new_session_does_not_reset_the_count(): void
    {
        $this->failSignIns(5);
        $this->forgetCookies();
        $this->failSignIns(5);
        $this->forgetCookies();

        $this->passwordSignIn('alice@example.org')->assertStatus(429);
    }

    public function test_a_verified_password_resets_the_count(): void
    {
        $this->failSignIns(9);
        $this->passwordSignIn('alice@example.org')->assertCreated();

        $this->failSignIns(1);

        $this->assertSame(0.0, $this->sleeper()->slept[9]);
        $this->passwordSignIn('alice@example.org')->assertCreated();
    }

    public function test_the_limit_and_window_are_configurable_and_the_window_expires(): void
    {
        config(['restv2.signin_max_failures' => 3, 'restv2.signin_failure_window' => 60]);

        $this->failSignIns(3);
        $response = $this->passwordSignIn('alice@example.org')->assertStatus(429);
        $this->assertLessThanOrEqual(60, $response->json('retryAfter'));

        $this->travel(61)->seconds();

        $this->passwordSignIn('alice@example.org')->assertCreated();
    }
}
