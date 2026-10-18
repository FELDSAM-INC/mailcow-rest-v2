<?php

namespace Tests\Feature\Routing\Security;

use App\Clients\Smtp\SmtpProbe;
use App\Clients\Smtp\StreamSmtpProbe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Routing\RoutingTestHelpers;
use Tests\Support\Smtp\ScriptedSmtpServer;
use Tests\TestCase;

/**
 * Feature 009 T099 (SC-003, FR-012, FR-043): every relayhost, transport and connection test flow
 * runs with known passwords; none of them, no prefix of three or more characters, and no base64 form
 * of the credentials appears in a response body, an audit record or a log line. The connection test
 * runs the real SMTP client against the scripted server.
 */
class RoutingSecretLeakScanTest extends TestCase
{
    use RoutingTestHelpers;

    private const PASSWORDS = ['S3cret-Rh!', 'pa:ss', 'x\\:y', 'Tr4nsp-Pw', 'N3w-Pw-Value'];

    private TestHandler $logs;

    /** @var list<string> */
    private array $bodies = [];

    private ?ScriptedSmtpServer $server = null;

    protected function setUp(): void
    {
        parent::setUp();

        $channel = Log::channel('stderr_json');
        $this->assertInstanceOf(\Illuminate\Log\Logger::class, $channel);
        $logger = $channel->getLogger();
        $this->assertInstanceOf(Logger::class, $logger);
        $logger->pushHandler($this->logs = new TestHandler);
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;

        parent::tearDown();
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return TestResponse<Response>
     */
    private function keep(TestResponse $response): TestResponse
    {
        $this->bodies[] = (string) $response->getContent();

        return $response;
    }

    public function test_no_password_leaks_into_responses_audit_records_or_logs(): void
    {
        $this->asAdmin();
        $f = $this->mailcow();
        $f->domain('example.org');
        $legacy = $f->relayhost(null, 'legacy.example', 'u\\:x', 'x\\:y');

        $id = (int) $this->keep($this->send('POST', '/api/v2/relayhosts', ['host' => 'r.example', 'username' => 'acct', 'password' => 'S3cret-Rh!']))->json('data.id');
        $this->keep($this->send('POST', '/api/v2/relayhosts', ['host' => 'p.example', 'username' => 'acct', 'password' => 'pa:ss']));
        $this->keep($this->send('POST', '/api/v2/relayhosts', ['host' => 'smtp:bad', 'username' => 'acct', 'password' => 'S3cret-Rh!']));
        $this->keep($this->send('PATCH', '/api/v2/relayhosts/'.$id, ['password' => 'N3w-Pw-Value']));
        $this->keep($this->send('PATCH', '/api/v2/relayhosts/'.$id, ['password' => 'N3w-Pw-Value', 'username' => 'a:b']));
        $this->keep($this->getJson('/api/v2/relayhosts'));
        $this->keep($this->getJson('/api/v2/relayhosts/'.$legacy));
        $this->keep($this->getJson('/api/v2/relayhost-choices'));
        $transport = (int) $this->keep($this->send('POST', '/api/v2/transports', ['destination' => 'example.com', 'nextHop' => '[hop.example]:25', 'username' => 'tu', 'password' => 'Tr4nsp-Pw']))->json('data.id');
        $this->keep($this->send('POST', '/api/v2/transports', ['destination' => 'example.net', 'nextHop' => '[hop.example]:25', 'password' => 'pa:ss']));
        $this->keep($this->send('PATCH', '/api/v2/transports/'.$transport, ['password' => 'Tr4nsp-Pw']));
        $this->keep($this->getJson('/api/v2/transports'));
        $this->keep($this->send('POST', '/api/v2/relayhosts/bulk-actions', ['action' => 'deactivate', 'targets' => [['id' => (string) $id], ['id' => (string) $legacy]]]));

        $this->server = new ScriptedSmtpServer([
            ['send' => '220 mx.test.example ESMTP'], ['expect' => 'EHLO'], ['send' => '250-mx.test.example'], ['send' => '250 AUTH LOGIN'],
            ['expect' => 'AUTH'], ['send' => '334 VXNlcm5hbWU6'], ['expect' => 'user'], ['send' => '334 UGFzc3dvcmQ6'], ['expect' => 'password'], ['send' => '235 ok'],
            ['expect' => 'MAIL'], ['send' => '250 ok'], ['expect' => 'RCPT'], ['send' => '250 ok'], ['expect' => 'DATA'], ['send' => '354 go'], ['data' => true], ['send' => '250 queued'],
            ['expect' => 'QUIT'], ['send' => '221 bye'],
        ]);
        $this->app->instance(SmtpProbe::class, new StreamSmtpProbe);
        $tested = $f->relayhost(null, '[127.0.0.1]:'.$this->server->port(), 'probe-user', 'S3cret-Rh!');
        $this->keep($this->send('POST', '/api/v2/relayhosts/'.$tested.'/connection-test'))->assertOk()->assertJsonPath('data.accepted', true);
        $this->keep($this->send('DELETE', '/api/v2/relayhosts/'.$id));

        $audit = DB::table('restv2_audit_events')->get()->map(fn (object $row): string => (string) json_encode($row))->implode("\n");
        $logs = implode("\n", array_map(fn ($record): string => (string) json_encode($record->toArray()), $this->logs->getRecords()));
        $haystacks = ['responses' => implode("\n", $this->bodies), 'audit records' => $audit, 'logs' => $logs];

        foreach (self::PASSWORDS as $password) {
            $needles = [$password, json_encode($password, JSON_UNESCAPED_SLASHES) === false ? $password : trim((string) json_encode($password, JSON_UNESCAPED_SLASHES), '"'), base64_encode($password), base64_encode("\0probe-user\0".$password)];

            if (strlen($password) >= 6) {
                $needles[] = substr($password, 0, 3);
            }

            foreach ($haystacks as $where => $haystack) {
                foreach (array_unique($needles) as $needle) {
                    $this->assertStringNotContainsString($needle, $haystack, "A password ({$password}) appears in the {$where}.");
                }
            }
        }

        $this->assertStringNotContainsString(base64_encode('probe-user'), $haystacks['responses']);
    }
}
