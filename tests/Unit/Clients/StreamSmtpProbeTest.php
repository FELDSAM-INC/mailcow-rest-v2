<?php

namespace Tests\Unit\Clients;

use App\Clients\Smtp\SmtpProbeRequest;
use App\Clients\Smtp\SmtpProbeResult;
use App\Clients\Smtp\StreamSmtpProbe;
use PHPUnit\Framework\TestCase;
use Tests\Support\Smtp\ScriptedSmtpServer;

/**
 * Feature 009 T058 (FR-042, FR-043, research R13, SC-007): the SMTP client of the connection test
 * against a scripted local server: steps, login mechanisms, STARTTLS, redaction at the source,
 * transcript caps and the step and overall deadlines.
 */
class StreamSmtpProbeTest extends TestCase
{
    private const USERNAME = 'acct-user';

    private const PASSWORD = 'S3cret-Pw!';

    private ?ScriptedSmtpServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;

        parent::tearDown();
    }

    /**
     * @param  list<array<string, mixed>>  $script
     */
    private function serve(array $script, bool $tls = false): int
    {
        $this->server = new ScriptedSmtpServer($script, $tls);

        return $this->server->port();
    }

    private function probe(int $port, ?string $username = self::USERNAME, float $step = 5, float $total = 20, string $sender = 'relay@example.org'): SmtpProbeResult
    {
        return (new StreamSmtpProbe)->probe(new SmtpProbeRequest(
            host: 'mx.test.example',
            addresses: ['127.0.0.1'],
            port: $port,
            heloName: 'mail.example.org',
            username: $username,
            password: $username === null ? null : self::PASSWORD,
            sender: $sender,
            recipient: 'null@hosted.mailcow.de',
            stepTimeout: $step,
            totalTimeout: $total,
        ));
    }

    /**
     * @param  list<string>  $capabilities
     * @return list<array<string, mixed>>
     */
    private static function greeting(array $capabilities): array
    {
        $steps = [['send' => '220 mx.test.example ESMTP'], ['expect' => 'EHLO']];
        $lines = ['mx.test.example', ...$capabilities];

        foreach ($lines as $i => $line) {
            $steps[] = ['send' => '250'.($i === count($lines) - 1 ? ' ' : '-').$line];
        }

        return $steps;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rest(): array
    {
        return [
            ['expect' => 'MAIL'], ['send' => '250 2.1.0 Ok'],
            ['expect' => 'RCPT'], ['send' => '250 2.1.5 Ok'],
            ['expect' => 'DATA'], ['send' => '354 End data with <CR><LF>.<CR><LF>'],
            ['data' => true], ['send' => '250 2.0.0 Ok: queued as ABC'],
            ['expect' => 'QUIT'], ['send' => '221 2.0.0 Bye'],
        ];
    }

    private static function secretsOf(SmtpProbeResult $result): string
    {
        return (string) json_encode($result);
    }

    public function test_an_accepted_message_with_starttls_and_plain(): void
    {
        $port = $this->serve([
            ...self::greeting(['STARTTLS', 'AUTH PLAIN LOGIN']),
            ['expect' => 'STARTTLS'], ['send' => '220 2.0.0 Ready to start TLS'], ['starttls' => true],
            ['expect' => 'EHLO'], ['send' => '250-mx.test.example'], ['send' => '250 AUTH PLAIN LOGIN'],
            ['expect' => 'AUTH'], ['send' => '235 2.7.0 Authentication successful'],
            ...self::rest(),
        ], tls: true);

        $result = $this->probe($port);
        $received = $this->server?->received() ?? [];

        $this->assertTrue($result->accepted);
        $this->assertSame('message', $result->step);
        $this->assertNull($result->failureReason);
        $this->assertTrue($result->tls);
        $this->assertSame('succeeded', $result->login);
        $this->assertSame(250, $result->lastReplyCode);
        $this->assertSame('EHLO mail.example.org', $received[0]);
        $this->assertSame('EHLO mail.example.org', $received[2]);
        $this->assertSame('AUTH PLAIN '.base64_encode("\0".self::USERNAME."\0".self::PASSWORD), $received[3]);
        $this->assertSame('MAIL FROM:<relay@example.org>', $received[4]);
        $this->assertSame('RCPT TO:<null@hosted.mailcow.de>', $received[5]);
        $this->assertContains('QUIT', $received);

        $texts = array_column($result->transcript, 'text');
        $this->assertContains('AUTH PLAIN [redacted]', $texts);
        $this->assertCount(1, array_filter($texts, fn (string $t): bool => preg_match('/^\[message: \d+ bytes\]$/', $t) === 1));
        $json = self::secretsOf($result);

        foreach ([self::USERNAME, self::PASSWORD, base64_encode(self::USERNAME), base64_encode(self::PASSWORD), base64_encode("\0".self::USERNAME."\0".self::PASSWORD), 'This is our test body'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_login_without_starttls_and_with_login_when_plain_is_not_offered(): void
    {
        $port = $this->serve([
            ...self::greeting(['AUTH LOGIN']),
            ['expect' => 'AUTH'], ['send' => '334 VXNlcm5hbWU6'],
            ['expect' => 'user'], ['send' => '334 UGFzc3dvcmQ6'],
            ['expect' => 'password'], ['send' => '235 2.7.0 Authentication successful'],
            ...self::rest(),
        ]);

        $result = $this->probe($port);
        $received = $this->server?->received() ?? [];

        $this->assertTrue($result->accepted);
        $this->assertFalse($result->tls);
        $this->assertSame(['AUTH LOGIN', base64_encode(self::USERNAME), base64_encode(self::PASSWORD)], array_slice($received, 1, 3));
        $texts = array_column($result->transcript, 'text');
        $this->assertContains('AUTH LOGIN', $texts);
        $this->assertContains('334 [redacted]', $texts);
        $this->assertSame(2, count(array_keys($texts, '[redacted]', true)));
        $this->assertStringNotContainsString('VXNlcm5hbWU6', self::secretsOf($result));
        $this->assertStringNotContainsString(base64_encode(self::PASSWORD), self::secretsOf($result));
    }

    public function test_no_username_means_no_login(): void
    {
        $port = $this->serve([...self::greeting(['AUTH PLAIN']), ...self::rest()]);

        $result = $this->probe($port, username: null);

        $this->assertTrue($result->accepted);
        $this->assertSame('notConfigured', $result->login);
        $this->assertNotContains('AUTH', array_map(fn (mixed $l): string => is_string($l) ? substr($l, 0, 4) : '', $this->server?->received() ?? []));
    }

    /**
     * The probe may extend a finite time limit for its dialogue (php-fpm), but it restores the
     * previous limit afterwards and leaves an unlimited one alone, so that a long-running process
     * (the console, the test suite) is not killed later.
     */
    public function test_the_process_time_limit_is_left_as_it_was(): void
    {
        $previous = (int) ini_get('max_execution_time');

        try {
            set_time_limit(0);
            $this->probe($this->serve([...self::greeting(['AUTH PLAIN']), ...self::rest()]), username: null);
            $this->assertSame('0', ini_get('max_execution_time'));
            $this->server?->stop();

            set_time_limit(30);
            $this->probe($this->serve([...self::greeting(['AUTH PLAIN']), ...self::rest()]), username: null);
            $this->assertSame('30', ini_get('max_execution_time'));
        } finally {
            set_time_limit($previous);
        }
    }

    public function test_login_not_offered_and_mechanism_not_supported(): void
    {
        $port = $this->serve(self::greeting(['SIZE 1000']));
        $result = $this->probe($port);
        $this->assertSame(['login', 'loginNotOffered', false, 'failed'], [$result->step, $result->failureReason, $result->accepted, $result->login]);
        $this->server?->stop();

        $port = $this->serve(self::greeting(['AUTH CRAM-MD5']));
        $result = $this->probe($port);
        $this->assertSame(['login', 'loginMechanismNotSupported'], [$result->step, $result->failureReason]);
    }

    public function test_a_refused_login(): void
    {
        $port = $this->serve([...self::greeting(['AUTH PLAIN']), ['expect' => 'AUTH'], ['send' => '535 5.7.8 Authentication failed']]);

        $result = $this->probe($port);

        $this->assertSame(['login', 'rejected', 'failed', 535], [$result->step, $result->failureReason, $result->login, $result->lastReplyCode]);
        $this->assertFalse($result->accepted);
    }

    public function test_refused_sender_recipient_and_message(): void
    {
        $port = $this->serve([...self::greeting([]), ['expect' => 'MAIL'], ['send' => '550 5.1.0 Sender rejected']]);
        $this->assertSame(['sender', 'rejected'], [($r = $this->probe($port, username: null))->step, $r->failureReason]);
        $this->server?->stop();

        $port = $this->serve([...self::greeting([]), ['expect' => 'MAIL'], ['send' => '250 Ok'], ['expect' => 'RCPT'], ['send' => '550 5.1.1 Recipient rejected']]);
        $this->assertSame(['recipient', 'rejected'], [($r = $this->probe($port, username: null))->step, $r->failureReason]);
        $this->server?->stop();

        $port = $this->serve([
            ...self::greeting([]), ['expect' => 'MAIL'], ['send' => '250 Ok'], ['expect' => 'RCPT'], ['send' => '250 Ok'],
            ['expect' => 'DATA'], ['send' => '354 go'], ['data' => true], ['send' => '554 5.7.1 Message rejected'],
        ]);
        $result = $this->probe($port, username: null);
        $this->assertSame(['message', 'rejected', false], [$result->step, $result->failureReason, $result->accepted]);
    }

    public function test_helo_is_used_when_ehlo_is_refused(): void
    {
        $port = $this->serve([
            ['send' => '220 old.example SMTP'], ['expect' => 'EHLO'], ['send' => '502 5.5.2 Error: command not recognized'],
            ['expect' => 'HELO'], ['send' => '250 old.example'],
            ...self::rest(),
        ]);

        $result = $this->probe($port, username: null);

        $this->assertTrue($result->accepted);
        $this->assertSame('HELO mail.example.org', ($this->server?->received() ?? [])[1]);
    }

    public function test_a_silent_server_times_out_at_the_greeting_within_the_step_timeout(): void
    {
        $port = $this->serve([['sleep' => 4]]);
        $started = microtime(true);

        $result = $this->probe($port, step: 1, total: 10);

        $this->assertSame(['greeting', 'timeout'], [$result->step, $result->failureReason]);
        $this->assertLessThan(3.0, microtime(true) - $started);
        $this->assertNull($result->lastReplyCode);
    }

    public function test_a_closed_port_is_refused(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $result = (new StreamSmtpProbe)->probe(new SmtpProbeRequest('mx.test.example', ['127.0.0.1'], (int) substr($name, (int) strrpos($name, ':') + 1), 'mail.example.org', null, null, 'a@example.org', 'b@example.org', 2, 5));

        $this->assertSame(['connect', 'connectionRefused'], [$result->step, $result->failureReason]);
    }

    public function test_a_server_closing_mid_dialogue(): void
    {
        $port = $this->serve([...self::greeting([]), ['expect' => 'MAIL'], ['close' => true]]);

        $result = $this->probe($port, username: null);

        $this->assertSame(['sender', 'connectionClosed'], [$result->step, $result->failureReason]);
    }

    public function test_a_failing_tls_handshake(): void
    {
        $port = $this->serve([...self::greeting(['STARTTLS']), ['expect' => 'STARTTLS'], ['send' => '220 Ready'], ['garbage' => true]]);

        $result = $this->probe($port, username: null);

        $this->assertSame(['tls', 'tlsFailed', false], [$result->step, $result->failureReason, $result->tls]);
    }

    public function test_the_overall_deadline_stops_a_slow_server(): void
    {
        $port = $this->serve([
            ['sleep' => 0.7], ['send' => '220 slow.example ESMTP'], ['expect' => 'EHLO'],
            ['sleep' => 0.7], ['send' => '250 slow.example'], ['expect' => 'MAIL'],
            ['sleep' => 0.7], ['send' => '250 Ok'], ['expect' => 'RCPT'],
            ['sleep' => 0.7], ['send' => '250 Ok'],
        ]);
        $started = microtime(true);

        $result = $this->probe($port, username: null, step: 1, total: 1.8);

        $this->assertSame('timeout', $result->failureReason);
        $this->assertContains($result->step, ['sender', 'recipient']);
        $this->assertLessThan(3.0, microtime(true) - $started);
    }

    public function test_server_text_is_sanitised_and_the_transcript_capped(): void
    {
        $capabilities = [];

        foreach (range(1, 520) as $i) {
            $capabilities[] = 'X-CAP'.$i;
        }

        $capabilities[] = "BELL\x07".str_repeat('a', 1200);
        $port = $this->serve([...self::greeting($capabilities), ...self::rest()]);

        $result = $this->probe($port, username: null);

        $this->assertTrue($result->accepted);
        $this->assertCount(501, $result->transcript);
        $this->assertSame('info', $result->transcript[500]['direction']);

        foreach ($result->transcript as $line) {
            $this->assertLessThanOrEqual(1000, mb_strlen($line['text']));
            $this->assertStringNotContainsString("\x07", $line['text']);
        }
    }

    public function test_message_lines_are_dot_stuffed_and_the_ehlo_name_is_sent(): void
    {
        $port = $this->serve([...self::greeting([]), ...self::rest()]);

        $result = $this->probe($port, username: null, sender: '.dot@example.org');
        $received = $this->server?->received() ?? [];
        $data = array_values(array_filter($received, 'is_array'))[0]['data'] ?? [];

        $this->assertTrue($result->accepted);
        $this->assertSame('EHLO mail.example.org', $received[0]);
        $this->assertContains('Subject: A subject for a SMTP test', $data);
        $this->assertContains('This is our test body', $data);
        $this->assertContains('From: Mailer <.dot@example.org>', $data);
        $this->assertNotContains('.', $data);
    }
}
