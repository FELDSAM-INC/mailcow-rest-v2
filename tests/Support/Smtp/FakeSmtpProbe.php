<?php

namespace Tests\Support\Smtp;

use App\Clients\Smtp\SmtpProbe;
use App\Clients\Smtp\SmtpProbeRequest;
use App\Clients\Smtp\SmtpProbeResult;
use PHPUnit\Framework\Assert;

/**
 * The connection test's SMTP client for feature tests (feature 009 T057): returns scripted results
 * in order (an accepted dialogue when none is queued) and records every request, so that no test
 * opens a socket.
 */
final class FakeSmtpProbe implements SmtpProbe
{
    /** @var list<SmtpProbeResult> */
    private array $results = [];

    /** @var list<SmtpProbeRequest> */
    private array $requests = [];

    public function willReturn(SmtpProbeResult ...$results): self
    {
        $this->results = [...$this->results, ...array_values($results)];

        return $this;
    }

    public function probe(SmtpProbeRequest $request): SmtpProbeResult
    {
        $this->requests[] = $request;

        return array_shift($this->results) ?? self::accepted();
    }

    /**
     * @return list<SmtpProbeRequest>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    public function assertProbed(int $times = 1): SmtpProbeRequest
    {
        Assert::assertCount($times, $this->requests, 'The SMTP probe was not called as expected.');

        return $this->requests[$times - 1];
    }

    public function assertNotProbed(): void
    {
        Assert::assertSame([], $this->requests, 'The SMTP probe was called.');
    }

    public static function accepted(bool $tls = true, string $login = 'succeeded'): SmtpProbeResult
    {
        return new SmtpProbeResult(true, 'message', null, null, $tls, $login, 250, '2.0.0 Ok: queued as ABC', [
            ['direction' => 'server', 'text' => '220 mx.example ESMTP'],
            ['direction' => 'client', 'text' => 'EHLO mail.example.org'],
            ['direction' => 'client', 'text' => 'AUTH PLAIN [redacted]'],
            ['direction' => 'server', 'text' => '235 2.7.0 Authentication successful'],
            ['direction' => 'client', 'text' => '[message: 240 bytes]'],
            ['direction' => 'server', 'text' => '250 2.0.0 Ok: queued as ABC'],
        ]);
    }

    public static function failed(string $step, string $reason, ?int $code = null, string $login = 'notReached'): SmtpProbeResult
    {
        return new SmtpProbeResult(false, $step, $reason, "The test failed at {$step}.", false, $login, $code, $code === null ? null : 'refused', [
            ['direction' => 'info', 'text' => "Failed at {$step}."],
        ]);
    }
}
