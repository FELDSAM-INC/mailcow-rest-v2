<?php

namespace Tests\Unit\Dkim;

use App\Clients\Ispconfig\IspconfigException;
use App\Clients\Ispconfig\JsonIspconfigDnsClient;
use App\Support\Clock\Deadline;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\Dns\FakeClock;
use Tests\TestCase;

/**
 * Feature 010 T012, together with feature 002 T011 (002 research D10, 010 research R11): the
 * ISPConfig JSON remote API adapter calls the remoting functions of the fork's SOAP client, maps
 * every failure to a reason code, honours the publication deadline and never reveals the password.
 */
class JsonIspconfigDnsClientTest extends TestCase
{
    private const URL = 'https://ispconfig.example:8080/remote/json.php';

    private const PASSWORD = 's3cret-Remote-Pw';

    /** @var list<array{function: string, body: array<string, mixed>, options: array<string, mixed>}> */
    private array $sent = [];

    /** @var array<string, mixed> function => answer (array body, or a Closure) */
    private array $answers = [];

    private FakeClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'restv2.dkim.dns_publication' => true,
            'restv2.ispconfig.url' => self::URL,
            'restv2.ispconfig.username' => 'remote',
            'restv2.ispconfig.password' => self::PASSWORD,
            'restv2.ispconfig.verify_tls' => true,
            'restv2.ispconfig.timeout' => 15,
        ]);
        $this->clock = new FakeClock;
        $this->answers = [
            'login' => ['code' => 'ok', 'message' => '', 'response' => 'session-1'],
            'logout' => ['code' => 'ok', 'message' => '', 'response' => true],
            'dns_zone_get' => ['code' => 'ok', 'message' => '', 'response' => [['id' => '7', 'origin' => 'example.org.', 'sys_groupid' => '3']]],
            'dns_rr_get_all_by_zone' => ['code' => 'ok', 'message' => '', 'response' => [
                ['id' => '11', 'zone' => '7', 'name' => 'dkim._domainkey', 'type' => 'TXT', 'data' => 'v=DKIM1;p=old'],
                ['id' => '12', 'zone' => '7', 'name' => 'mail', 'type' => 'A', 'data' => '192.0.2.1'],
            ]],
            'dns_txt_add' => ['code' => 'ok', 'message' => '', 'response' => '21'],
            'dns_txt_update' => ['code' => 'ok', 'message' => '', 'response' => 1],
            'dns_txt_delete' => ['code' => 'ok', 'message' => '', 'response' => 1],
        ];

        Http::fake(function (Request $request, array $options) {
            $function = (string) parse_url($request->url(), PHP_URL_QUERY);
            $this->sent[] = ['function' => $function, 'body' => (array) json_decode($request->body(), true), 'options' => $options];
            $answer = $this->answers[$function] ?? ['code' => 'remote_fault', 'message' => 'unknown function', 'response' => false];

            return $answer instanceof \Closure ? $answer($request) : Http::response($answer);
        });
    }

    private function client(): JsonIspconfigDnsClient
    {
        return $this->app->make(JsonIspconfigDnsClient::class);
    }

    private function open(): JsonIspconfigDnsClient
    {
        $client = $this->client();
        $client->open(new Deadline($this->clock, 15));

        return $client;
    }

    /**
     * @return list<string>
     */
    private function functions(): array
    {
        return array_column($this->sent, 'function');
    }

    private function expectReason(string $reason, callable $call): IspconfigException
    {
        try {
            $call();
        } catch (IspconfigException $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertStringNotContainsString(self::PASSWORD, $e->getMessage());
            $this->assertStringNotContainsString(self::PASSWORD, (string) $e->detail);

            return $e;
        }

        $this->fail("expected an IspconfigException with reason {$reason}");
    }

    public function test_the_exact_requests(): void
    {
        $client = $this->open();
        $zone = $client->findZone('example.org.');
        $this->assertNotNull($zone);
        $records = $client->records($zone->id);
        $client->addTxt($zone->sysGroupId - 1, ['name' => 'new._domainkey']);
        $client->updateTxt($zone->sysGroupId - 1, 11, ['name' => 'dkim._domainkey']);
        $client->deleteTxt(11);
        $client->close();

        $this->assertSame(['login', 'dns_zone_get', 'dns_rr_get_all_by_zone', 'dns_txt_add', 'dns_txt_update', 'dns_txt_delete', 'logout'], $this->functions());
        $this->assertSame(['username' => 'remote', 'password' => self::PASSWORD], $this->sent[0]['body']);
        $this->assertSame(['session_id' => 'session-1', 'primary_id' => ['origin' => 'example.org.']], $this->sent[1]['body']);
        $this->assertSame(['session_id' => 'session-1', 'zone_id' => 7], $this->sent[2]['body']);
        $this->assertSame(['session_id' => 'session-1', 'client_id' => 2, 'params' => ['name' => 'new._domainkey']], $this->sent[3]['body']);
        $this->assertSame(['session_id' => 'session-1', 'client_id' => 2, 'primary_id' => 11, 'params' => ['name' => 'dkim._domainkey']], $this->sent[4]['body']);
        $this->assertSame(['session_id' => 'session-1', 'primary_id' => 11], $this->sent[5]['body']);
        $this->assertSame(['session_id' => 'session-1'], $this->sent[6]['body']);

        $this->assertSame(7, $zone->id);
        $this->assertSame('example.org.', $zone->origin);
        $this->assertSame(3, $zone->sysGroupId);
        $this->assertCount(2, $records);
        $this->assertSame(11, $records[0]->id);
        $this->assertSame('dkim._domainkey', $records[0]->name);
        $this->assertSame('TXT', $records[0]->type);
    }

    public function test_no_zone_gives_null(): void
    {
        $this->answers['dns_zone_get'] = ['code' => 'ok', 'message' => '', 'response' => []];

        $this->assertNull($this->open()->findZone('example.org.'));
    }

    public function test_logout_is_sent_once_even_after_a_failure(): void
    {
        $this->answers['dns_zone_get'] = ['code' => 'remote_fault', 'message' => 'The zone is broken', 'response' => false];
        $client = $this->open();

        $this->expectReason('remoteFault', fn () => $client->findZone('example.org.'));
        $client->close();
        $client->close();

        $this->assertSame(1, count(array_keys($this->functions(), 'logout', true)));
    }

    public function test_failures_map_to_reason_codes(): void
    {
        $this->answers['login'] = ['code' => 'remote_fault', 'message' => 'The login failed. Username or password wrong.', 'response' => false];
        $this->expectReason('authenticationFailed', fn () => $this->open());

        $this->answers['login'] = fn () => Http::failedConnection('cURL error 7: Failed to connect to ispconfig.example port 8080');
        $this->expectReason('unreachable', fn () => $this->open());

        $this->answers['login'] = fn () => Http::failedConnection('cURL error 28: Operation timed out after 15000 milliseconds');
        $this->expectReason('timeout', fn () => $this->open());

        $this->answers['login'] = fn () => Http::response('Internal error', 500);
        $this->expectReason('invalidResponse', fn () => $this->open());

        $this->answers['login'] = fn () => Http::response('<html>not json</html>', 200);
        $this->expectReason('invalidResponse', fn () => $this->open());

        $this->answers['login'] = ['code' => 'ok', 'message' => '', 'response' => ['not' => 'a session']];
        $this->expectReason('invalidResponse', fn () => $this->open());
    }

    public function test_a_wrongly_shaped_record_list_is_an_invalid_response(): void
    {
        $this->answers['dns_rr_get_all_by_zone'] = ['code' => 'ok', 'message' => '', 'response' => 'nonsense'];
        $client = $this->open();

        $this->expectReason('invalidResponse', fn () => $client->records(7));
    }

    public function test_the_deadline_caps_every_call_and_stops_before_the_last_half_second(): void
    {
        $client = $this->open();
        $this->clock->advance(10.0);
        $client->findZone('example.org.');

        $this->assertEqualsWithDelta(5.0, $this->sent[1]['options']['timeout'], 0.01);
        $this->assertLessThanOrEqual(5.0, $this->sent[1]['options']['connect_timeout']);

        $this->clock->advance(4.6);
        $this->expectReason('timeout', fn () => $client->records(7));
        $this->assertNotContains('dns_rr_get_all_by_zone', $this->functions());
    }

    public function test_tls_verification_is_off_only_when_configured(): void
    {
        $this->open();
        $this->assertTrue($this->sent[0]['options']['verify']);

        config(['restv2.ispconfig.verify_tls' => false]);
        $before = count($this->sent);
        $this->open();
        $this->assertFalse($this->sent[$before]['options']['verify']);
    }

    public function test_the_password_never_appears_in_messages_or_logs(): void
    {
        $log = Log::spy();
        $this->answers['login'] = ['code' => 'remote_fault', 'message' => 'Wrong password '.self::PASSWORD, 'response' => false];

        $e = $this->expectReason('authenticationFailed', fn () => $this->open());

        $this->assertStringContainsString('***', (string) $e->detail);
        $log->shouldNotHaveReceived('error', fn (string $message): bool => str_contains($message, self::PASSWORD));
        $log->shouldNotHaveReceived('warning', fn (string $message): bool => str_contains($message, self::PASSWORD));
        $this->assertStringNotContainsString(self::PASSWORD, print_r($this->client(), true));
    }
}
