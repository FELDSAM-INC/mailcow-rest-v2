<?php

namespace Tests\Unit\Clients;

use App\Clients\Rspamd\RspamdUnavailable;
use App\Clients\Rspamd\UnixSocketRspamdController;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Monitoring\MonitoringFixtures;
use Tests\TestCase;

/**
 * Feature 011 T095 (research R16; feature 006 research R2): the Rspamd controller over its Unix
 * socket, as legacy calls it, without a password.
 */
class UnixSocketRspamdControllerHistoryTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $options = [];

    private function client(): UnixSocketRspamdController
    {
        return $this->app->make(UnixSocketRspamdController::class);
    }

    public function test_history_is_read_over_the_socket(): void
    {
        config(['restv2.rspamd.socket' => '/var/lib/rspamd/rspamd.sock']);
        Http::fake(function (Request $request, array $options) {
            $this->options[] = $options;

            return Http::response(MonitoringFixtures::json('rspamd-history.json'));
        });

        $history = $this->client()->history();

        $this->assertSame(2, $history['version']);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'GET' && $r->url() === 'http://rspamd/history' && ! $r->hasHeader('Password'));
        $this->assertSame('/var/lib/rspamd/rspamd.sock', $this->options[0]['curl'][CURLOPT_UNIX_SOCKET_PATH] ?? null);
        $this->assertEquals(10, $this->options[0]['timeout']);
        $this->assertEquals(5, $this->options[0]['connect_timeout']);
    }

    public function test_stat_is_read_over_the_socket(): void
    {
        Http::fake(fn () => Http::response(MonitoringFixtures::json('rspamd-stat.json')));

        $this->assertSame(12345, $this->client()->stat()['scanned']);
        Http::assertSent(fn (Request $r): bool => $r->url() === 'http://rspamd/stat');
    }

    public function test_a_connection_failure_is_unavailable(): void
    {
        Http::fake(fn () => Http::failedConnection('cURL error 7: Failed to connect to rspamd.sock'));

        $this->expectException(RspamdUnavailable::class);
        $this->client()->history();
    }

    public function test_an_error_status_is_unavailable(): void
    {
        Http::fake(fn () => Http::response(['error' => 'internal'], 500));

        $this->expectException(RspamdUnavailable::class);
        $this->client()->history();
    }

    public function test_a_document_that_is_not_json_is_unavailable(): void
    {
        Http::fake(fn () => Http::response('<html>bad gateway</html>', 200, ['Content-Type' => 'text/html']));

        $this->expectException(RspamdUnavailable::class);
        $this->client()->stat();
    }
}
