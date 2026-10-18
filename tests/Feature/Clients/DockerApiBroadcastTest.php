<?php

namespace Tests\Feature\Clients;

use App\Clients\DockerApi\HttpDockerApiClient;
use Tests\Support\Redis\ChannelSpy;
use Tests\TestCase;

/**
 * Feature 003 T012 (research R16): a broadcast is legacy docker('broadcast'), published on
 * mailcow's MC_CHANNEL exactly as legacy json_encode()s it, so that every mail node of a
 * replicated cluster runs the command.
 */
class DockerApiBroadcastTest extends TestCase
{
    public function test_a_broadcast_publishes_the_legacy_request_on_mc_channel(): void
    {
        $config = config('database.redis.mailcow');
        $spy = new ChannelSpy((string) $config['host'], (int) $config['port'], $config['password'] ?? null, 'MC_CHANNEL');

        $this->app->make(HttpDockerApiClient::class)->broadcast('dovecot-mailcow', ['cmd' => 'maildir', 'task' => 'cleanup', 'maildir' => 'd/l']);

        $spy->assertPublished('{"api_call":"container_post","container_name":"dovecot-mailcow","post_action":"exec","request":{"cmd":"maildir","task":"cleanup","maildir":"d\/l"}}');
        $spy->stop();
    }
}
