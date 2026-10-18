<?php

namespace Tests\Support\Smtp;

use App\Clients\Smtp\SmtpProbe;
use App\Clients\Smtp\SmtpProbeRequest;
use App\Clients\Smtp\SmtpProbeResult;
use PHPUnit\Framework\Assert;

/**
 * The connection test's SMTP client for feature tests (feature 009 T057): returns scripted results
 * in order (an accepted dialogue when none is queued) and records every request, so that no test
 * opens a socket. Feature 010 (T048) adds the TLS-only request of the TLSA check: a request with
 * `stopAfterTls` answers a certificate (certificate()) or a failing step (tlsFailure()); unless
 * one is queued, it answers the certificate set with tlsCertificate().
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

    private ?SmtpProbeResult $tlsResult = null;

    public function probe(SmtpProbeRequest $request): SmtpProbeResult
    {
        $this->requests[] = $request;

        if ($request->stopAfterTls && $this->results === []) {
            return $this->tlsResult ?? self::tlsFailure('connect', 'connectionRefused');
        }

        return array_shift($this->results) ?? self::accepted();
    }

    /**
     * The answer of every TLS-only request (unless another result is queued).
     */
    public function tlsCertificate(SmtpProbeResult $result): self
    {
        $this->tlsResult = $result;

        return $this;
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

    /**
     * A TLS-only request (feature 010 T048) that completed the STARTTLS handshake and read the
     * peer certificate (PEM), or none when the server presented no certificate.
     */
    public static function certificate(?string $pem): SmtpProbeResult
    {
        return new SmtpProbeResult($pem !== null, 'tls', $pem === null ? 'noCertificate' : null, $pem === null ? 'The server presented no certificate.' : null, true, 'notConfigured', 250, 'mx.example ESMTP', [
            ['direction' => 'server', 'text' => '220 mx.example ESMTP'],
            ['direction' => 'client', 'text' => 'EHLO mail.example.org'],
            ['direction' => 'client', 'text' => 'STARTTLS'],
        ], $pem);
    }

    /**
     * A TLS-only request that stopped at a step: `connect` (connectionRefused, connectionFailed,
     * timeout), `greeting` (timeout), or `tls` (starttlsNotOffered, rejected, tlsFailed).
     */
    public static function tlsFailure(string $step, string $reason): SmtpProbeResult
    {
        return new SmtpProbeResult(false, $step, $reason, "The TLS probe failed at {$step}.", false, 'notConfigured', null, null, [
            ['direction' => 'info', 'text' => "Failed at {$step}."],
        ]);
    }

    public static function failed(string $step, string $reason, ?int $code = null, string $login = 'notReached'): SmtpProbeResult
    {
        return new SmtpProbeResult(false, $step, $reason, "The test failed at {$step}.", false, $login, $code, $code === null ? null : 'refused', [
            ['direction' => 'info', 'text' => "Failed at {$step}."],
        ]);
    }
}
