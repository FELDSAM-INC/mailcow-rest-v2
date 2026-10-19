<?php

namespace Tests\Unit\Clients;

use App\Clients\Mail\DeliveryOutcome;
use App\Clients\Mail\SmtpQuarantineReleaseMailer;
use Symfony\Component\Mime\RawMessage;
use Tests\Support\Smtp\ScriptedSmtpServer;
use Tests\TestCase;

/**
 * Feature 006 T015 (research R3, FR-019, FR-021): releases go to Postfix's quarantine submission
 * with `EHLO quarantine`, the stored sender and the recipient, never with STARTTLS, with CRLF line
 * endings and dot-stuffing; the mail server's answers are classified as accepted, refused (`5xx`)
 * or temporary failures (`4xx`, unreachable, timeout).
 */
class SmtpQuarantineReleaseMailerTest extends TestCase
{
    private ?ScriptedSmtpServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        parent::tearDown();
    }

    /**
     * @param  list<array<string, mixed>>  $script
     */
    private function serve(array $script): void
    {
        $this->server = new ScriptedSmtpServer($script);
        config(['restv2.release_smtp.host' => '127.0.0.1', 'restv2.release_smtp.port' => $this->server->port(), 'restv2.release_smtp.timeout' => 5]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function accepting(): array
    {
        return [
            ['send' => '220 mail.example.org ESMTP Postfix'],
            ['expect' => 'EHLO'],
            ['send' => '250-mail.example.org'],
            ['send' => '250-PIPELINING'],
            ['send' => '250-STARTTLS'],
            ['send' => '250 8BITMIME'],
            ['expect' => 'MAIL'],
            ['send' => '250 2.1.0 Ok'],
            ['expect' => 'RCPT'],
            ['send' => '250 2.1.5 Ok'],
            ['expect' => 'DATA'],
            ['send' => '354 End data with <CR><LF>.<CR><LF>'],
            ['rawData' => true],
            ['send' => '250 2.0.0 Ok: queued as 4XyZ1q2w3eR'],
            ['expect' => 'QUIT'],
            ['send' => '221 2.0.0 Bye'],
        ];
    }

    private function mailer(): SmtpQuarantineReleaseMailer
    {
        return $this->app->make(SmtpQuarantineReleaseMailer::class);
    }

    public function test_the_dialogue_is_legacys_without_starttls(): void
    {
        $this->serve(self::accepting());

        $outcome = $this->mailer()->deliver(new RawMessage("Subject: Hi\r\n\r\nHello\r\n"), 'sender@example.net', 'alice@example.org');

        $this->assertTrue($outcome->isAccepted());
        $received = $this->server?->received() ?? [];
        $this->assertSame('EHLO quarantine', $received[0]);
        $this->assertSame('MAIL FROM:<sender@example.net>', $received[1]);
        $this->assertSame('RCPT TO:<alice@example.org>', $received[2]);
        $this->assertSame('DATA', $received[3]);
        $this->assertIsArray($received[4]);
        $this->assertSame('QUIT', $received[5]);
        $this->assertNotContains('STARTTLS', $received);
    }

    public function test_lf_line_endings_are_sent_as_crlf_and_leading_dots_are_stuffed(): void
    {
        $this->serve(self::accepting());

        $this->mailer()->deliver(new RawMessage("Subject: Dots\n\nfirst\n.hidden\n..two\nlast\n"), 'sender@example.net', 'alice@example.org');

        $received = $this->server?->received() ?? [];
        $data = $received[4];
        $this->assertIsArray($data);
        $raw = base64_decode((string) ($data['rawData'] ?? ''));
        $this->assertStringStartsWith("Subject: Dots\r\n\r\nfirst\r\n..hidden\r\n...two\r\nlast\r\n", $raw);
        $this->assertStringEndsWith("\r\n.\r\n", $raw);
        $this->assertDoesNotMatchRegularExpression("/(?<!\r)\n/", $raw);
    }

    public function test_a_permanent_refusal_at_rcpt_is_refused_with_the_reply(): void
    {
        $this->serve([
            ['send' => '220 mail.example.org ESMTP Postfix'],
            ['expect' => 'EHLO'],
            ['send' => '250 mail.example.org'],
            ['expect' => 'MAIL'],
            ['send' => '250 2.1.0 Ok'],
            ['expect' => 'RCPT'],
            ['send' => '550 5.1.1 <gone@example.org>: Recipient address rejected: User unknown in virtual mailbox table'],
        ]);

        $outcome = $this->mailer()->deliver(new RawMessage("Subject: Hi\r\n\r\nHello\r\n"), 'sender@example.net', 'gone@example.org');

        $this->assertTrue($outcome->isRefused());
        $this->assertSame(550, $outcome->code);
        $this->assertSame('550 5.1.1 <gone@example.org>: Recipient address rejected: User unknown in virtual mailbox table', $outcome->reply);
    }

    public function test_a_temporary_refusal_is_a_temporary_failure(): void
    {
        $this->serve([
            ['send' => '220 mail.example.org ESMTP Postfix'],
            ['expect' => 'EHLO'],
            ['send' => '250 mail.example.org'],
            ['expect' => 'MAIL'],
            ['send' => '451 4.3.0 Error: queue file write error'],
        ]);

        $outcome = $this->mailer()->deliver(new RawMessage("Subject: Hi\r\n\r\nHello\r\n"), 'sender@example.net', 'alice@example.org');

        $this->assertTrue($outcome->isTemporaryFailure());
        $this->assertStringContainsString('451', (string) $outcome->reason);
    }

    public function test_a_refused_connection_is_a_temporary_failure(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        config(['restv2.release_smtp.host' => '127.0.0.1', 'restv2.release_smtp.port' => (int) substr($name, (int) strrpos($name, ':') + 1), 'restv2.release_smtp.timeout' => 1]);

        $outcome = $this->mailer()->deliver(new RawMessage("Subject: Hi\r\n\r\nHello\r\n"), 'sender@example.net', 'alice@example.org');

        $this->assertTrue($outcome->isTemporaryFailure());
    }

    public function test_a_server_that_never_answers_times_out(): void
    {
        $this->serve([['sleep' => 4]]);
        config(['restv2.release_smtp.timeout' => 1]);

        $started = microtime(true);
        $outcome = $this->mailer()->deliver(new RawMessage("Subject: Hi\r\n\r\nHello\r\n"), 'sender@example.net', 'alice@example.org');

        $this->assertTrue($outcome->isTemporaryFailure());
        $this->assertLessThan(3.5, microtime(true) - $started);
    }

    public function test_outcome_constructors(): void
    {
        $this->assertTrue(DeliveryOutcome::accepted()->isAccepted());
        $this->assertSame(554, DeliveryOutcome::refused(554, '554 no')->code);
        $this->assertSame('timeout', DeliveryOutcome::temporaryFailure('timeout')->reason);
    }
}
