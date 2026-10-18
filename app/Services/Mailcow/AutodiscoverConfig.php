<?php

namespace App\Services\Mailcow;

use App\Services\Config\MailcowConfig;

/**
 * The mail service hosts and ports that legacy's `$autodiscover_config` announces and DNS
 * diagnostics expects in SRV records (feature 010 research R17): the operator's values (server
 * setting RESTV2_AUTODISCOVER_CONFIG, else the mounted vars files), and where an entry, host or port
 * is missing or not a valid port (upstream's file reads the port variables from an environment that
 * may lack them), the server host name and the ports of mailcow.conf's port variables
 * (`restv2.mailcow_ports`, upstream defaults 143, 993, 110, 995, 465, 587 and 4190). The `sieve`
 * entry falls back to SIEVE_PORT, as legacy dns_diagnostics.php does.
 */
final readonly class AutodiscoverConfig
{
    public function __construct(
        public string $imapHost,
        public int $imapPort,
        public int $imapsPort,
        public string $pop3Host,
        public int $pop3Port,
        public int $pop3sPort,
        public string $smtpHost,
        public int $submissionPort,
        public int $smtpsPort,
        public string $sieveHost,
        public int $sievePort,
    ) {}

    public static function resolve(MailcowConfig $config): self
    {
        $entries = $config->autodiscoverConfig();
        $hostname = strtolower((string) config('restv2.mailcow_hostname'));
        $ports = (array) config('restv2.mailcow_ports');
        $host = fn (string $service): string => self::host($entries[$service]['server'] ?? null) ?? $hostname;
        $port = fn (string $service, string $key, string $variable, int $default): int => self::port($entries[$service][$key] ?? null) ?? self::port($ports[$variable] ?? null) ?? $default;

        return new self(
            imapHost: $host('imap'),
            imapPort: $port('imap', 'tlsport', 'IMAP_PORT', 143),
            imapsPort: $port('imap', 'port', 'IMAPS_PORT', 993),
            pop3Host: $host('pop3'),
            pop3Port: $port('pop3', 'tlsport', 'POP_PORT', 110),
            pop3sPort: $port('pop3', 'port', 'POPS_PORT', 995),
            smtpHost: $host('smtp'),
            submissionPort: $port('smtp', 'tlsport', 'SUBMISSION_PORT', 587),
            smtpsPort: $port('smtp', 'port', 'SMTPS_PORT', 465),
            sieveHost: $host('sieve'),
            sievePort: $port('sieve', 'port', 'SIEVE_PORT', 4190),
        );
    }

    private static function host(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? strtolower(rtrim(trim($value), '.')) : null;
    }

    private static function port(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value >= 1 && (int) $value <= 65535 ? (int) $value : null;
    }
}
