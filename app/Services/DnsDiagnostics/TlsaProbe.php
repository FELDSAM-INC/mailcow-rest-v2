<?php

namespace App\Services\DnsDiagnostics;

use App\Clients\Smtp\SmtpProbe;
use App\Clients\Smtp\SmtpProbeRequest;

/**
 * The expected TLSA value of the SMTP host (feature 010 FR-042, research R19): feature 009's SMTP
 * client connects to port 25, sends `EHLO <MAILCOW_HOSTNAME>` and `STARTTLS`, captures the peer
 * certificate during the handshake (unverified) and quits, all within RESTV2_DNS_LOOKUP_TIMEOUT
 * (5 s). Its failing step names the cause (`connectionFailed`, `timeout`, `starttlsRefused`,
 * `tlsHandshakeFailed`, `noCertificate`). Digests are reused through ServerFactsCache; failures are
 * not.
 */
class TlsaProbe
{
    public function __construct(
        private readonly SmtpProbe $probe,
        private readonly ServerFactsCache $cache,
    ) {}

    /**
     * @return array{digest: string|null, cause: string|null}
     */
    public function digest(string $host, string $heloName): array
    {
        $cached = $this->cache->get('tlsa:'.$host);

        if ($cached !== null && str_starts_with($cached, '3 1 1 ')) {
            return ['digest' => $cached, 'cause' => null];
        }

        $timeout = (float) max(1, (int) config('restv2.dns.lookup_timeout', 5));
        $result = $this->probe->probe(new SmtpProbeRequest($host, [$host], 25, $heloName, null, null, '', '', $timeout, $timeout, stopAfterTls: true, capturePeerCertificate: true));
        $digest = $result->accepted && $result->peerCertificate !== null ? TlsaDigest::fromCertificate($result->peerCertificate) : null;

        if ($digest !== null) {
            $this->cache->put('tlsa:'.$host, $digest);

            return ['digest' => $digest, 'cause' => null];
        }

        $cause = match (true) {
            $result->failureReason === 'timeout' => 'timeout',
            $result->step === 'connect' => 'connectionFailed',
            in_array($result->failureReason, ['starttlsNotOffered', 'rejected'], true) && $result->step === 'tls' => 'starttlsRefused',
            $result->failureReason === 'tlsFailed' => 'tlsHandshakeFailed',
            $result->accepted, $result->failureReason === 'noCertificate' => 'noCertificate',
            default => 'connectionFailed',
        };

        return ['digest' => null, 'cause' => $cause];
    }
}
