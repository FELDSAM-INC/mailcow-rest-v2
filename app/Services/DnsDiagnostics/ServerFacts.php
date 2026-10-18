<?php

namespace App\Services\DnsDiagnostics;

/**
 * The server values a run compares with (feature 010 FR-042): the host name, the public
 * addresses (IPv4 null when unknown, IPv6 null when the server has none), the SMTP host of
 * `$autodiscover_config`, and the TLSA digest of its port-25 certificate or the cause it could not be
 * read.
 */
final readonly class ServerFacts
{
    public function __construct(
        public string $hostname,
        public ?string $ipv4,
        public ?string $ipv6,
        public string $smtpHost,
        public ?string $tlsaDigest,
        public ?string $tlsaFailureCause,
    ) {}
}
