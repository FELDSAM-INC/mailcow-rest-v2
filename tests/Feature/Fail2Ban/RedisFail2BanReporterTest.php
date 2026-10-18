<?php

namespace Tests\Feature\Fail2Ban;

use App\Clients\Fail2Ban\Fail2BanReporter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Redis\ChannelSpy;
use Tests\TestCase;

/**
 * T032 (FR-006, FR-040, R18): invalid credentials are published to mailcow's F2B_CHANNEL with the
 * exact text netfilter matches, hardened against log injection.
 */
class RedisFail2BanReporterTest extends TestCase
{
    private function reporter(): Fail2BanReporter
    {
        return $this->app->make(Fail2BanReporter::class);
    }

    public function test_an_invalid_password_is_published(): void
    {
        $spy = ChannelSpy::fail2ban();

        $this->reporter()->invalidPassword('alice@example.org', '192.0.2.10');

        $spy->assertPublished('mailcow UI: Invalid password for alice@example.org by 192.0.2.10');
    }

    public function test_an_invalid_api_key_is_published_as_api_user(): void
    {
        $spy = ChannelSpy::fail2ban();

        $this->reporter()->invalidApiKey('192.0.2.11');

        $spy->assertPublished('mailcow UI: Invalid password for API_USER by 192.0.2.11');
    }

    public function test_ipv6_addresses_are_canonical(): void
    {
        $spy = ChannelSpy::fail2ban();

        $this->reporter()->invalidPassword('bob@example.org', '2001:0db8::0001');

        $spy->assertPublished('mailcow UI: Invalid password for bob@example.org by 2001:db8::1');
    }

    public function test_control_characters_cannot_inject_a_second_line(): void
    {
        $spy = ChannelSpy::fail2ban();

        $this->reporter()->invalidPassword("x by 203.0.113.9\nmailcow UI: Invalid password for y\r", '192.0.2.12');

        $spy->assertPublished('mailcow UI: Invalid password for x by 203.0.113.9?mailcow UI: Invalid password for y? by 192.0.2.12');
        $this->assertCount(1, $spy->messages(1));
    }

    public function test_long_usernames_are_cut_to_255_bytes(): void
    {
        $spy = ChannelSpy::fail2ban();

        $this->reporter()->invalidPassword(str_repeat('a', 300), '192.0.2.13');

        $spy->assertPublished('mailcow UI: Invalid password for '.str_repeat('a', 255).' by 192.0.2.13');
    }

    public function test_an_unreachable_redis_is_logged_and_the_caller_continues(): void
    {
        // The Redis manager keeps the configuration it was built with: rebuild it.
        config(['database.redis.mailcow.port' => 1]);
        $this->app->forgetInstance('redis');
        Redis::clearResolvedInstances();
        $log = Log::spy();

        // No exception reaches the caller.
        $this->reporter()->invalidPassword('alice@example.org', '192.0.2.10');

        $log->shouldHaveReceived('error')->once();
    }
}
