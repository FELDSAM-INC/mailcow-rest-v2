<?php

namespace Tests\Unit\Clients;

use App\Clients\Lmtp\LmtpResult;
use App\Clients\Lmtp\SocketLmtpClient;
use Tests\Support\Lmtp\LmtpStubServer;
use Tests\TestCase;

/**
 * Feature 013 T064 (research R18): mass mail is delivered over LMTP to Dovecot as legacy's
 * sys_mail() delivers it: LHLO, MAIL FROM, RCPT TO, DATA with dot-stuffing and CRLF line endings,
 * one reply per recipient; the connection is reused with RSET and reopened after an error; every
 * transaction is bounded by the delivery deadline.
 */
class SocketLmtpClientTest extends TestCase
{
    private ?LmtpStubServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $script
     */
    private function client(array $script = [], float $deadline = 5.0): SocketLmtpClient
    {
        $this->server = new LmtpStubServer($script);

        return new SocketLmtpClient('127.0.0.1', $this->server->port, 'mail.example.org', $deadline);
    }

    public function test_a_message_is_delivered_with_the_lmtp_dialogue_and_dot_stuffing(): void
    {
        $client = $this->client();

        $result = $client->deliver('noreply@mail.example.org', 'alice@example.org', "Subject: Hi\n\nfirst\n.hidden dot\nlast\n");
        $client->close();

        $this->assertSame([LmtpResult::DELIVERED, 250], [$result->status, $result->code]);
        $log = $this->server?->log() ?? [];
        $this->assertContains('C: LHLO mail.example.org', $log);
        $this->assertContains('C: MAIL FROM:<noreply@mail.example.org>', $log);
        $this->assertContains('C: RCPT TO:<alice@example.org>', $log);
        $this->assertContains('C: DATA', $log);
        $this->assertContains('C: ..hidden dot', $log, 'A line starting with a dot is dot-stuffed.');
        $this->assertContains('C: QUIT', $log);
    }

    public function test_replies_map_to_outcomes_and_the_connection_is_reused_with_rset(): void
    {
        $client = $this->client(['finals' => ['250 2.0.0 Saved', '452 4.2.2 Mailbox is full', '552 5.2.2 Quota exceeded']]);

        $first = $client->deliver('a@x.example', 'one@example.org', "Subject: 1\n\nx\n");
        $second = $client->deliver('a@x.example', 'two@example.org', "Subject: 2\n\nx\n");
        $third = $client->deliver('a@x.example', 'three@example.org', "Subject: 3\n\nx\n");

        $this->assertSame(LmtpResult::DELIVERED, $first->status);
        $this->assertSame([LmtpResult::TEMPORARY_FAILURE, 452, '4.2.2 Mailbox is full'], [$second->status, $second->code, $second->text]);
        $this->assertSame([LmtpResult::PERMANENT_FAILURE, 552], [$third->status, $third->code]);
        $log = $this->server?->log() ?? [];
        $this->assertCount(1, array_filter($log, fn (string $l): bool => str_starts_with($l, 'C: LHLO')), 'One connection for all messages.');
        $this->assertCount(2, array_filter($log, fn (string $l): bool => $l === 'C: RSET'));
    }

    public function test_a_refused_connection_is_a_temporary_failure(): void
    {
        $client = new SocketLmtpClient('127.0.0.1', 1, 'mail.example.org', 1.0);

        $result = $client->deliver('a@x.example', 'one@example.org', "Subject: 1\n\nx\n");

        $this->assertTrue($result->isTemporary());
        $this->assertTrue($result->connectionFailed);
    }

    public function test_a_dropped_connection_is_reopened_for_the_next_message(): void
    {
        $client = $this->client(['dropFirst' => true]);

        $first = $client->deliver('a@x.example', 'one@example.org', "Subject: 1\n\nx\n");
        $second = $client->deliver('a@x.example', 'one@example.org', "Subject: 1\n\nx\n");

        $this->assertTrue($first->isTemporary());
        $this->assertTrue($second->isDelivered());
    }

    public function test_a_stall_past_the_deadline_never_sends_the_final_dot(): void
    {
        $client = $this->client(['stallData' => 2], 1.0);
        $started = microtime(true);

        $result = $client->deliver('a@x.example', 'one@example.org', "Subject: 1\n\nx\n");

        $this->assertTrue($result->isTemporary());
        $this->assertStringContainsString('timeout', $result->text);
        $this->assertLessThan(1.9, microtime(true) - $started);
        $this->assertNotContains('C: .', $this->server?->log() ?? []);
    }
}
