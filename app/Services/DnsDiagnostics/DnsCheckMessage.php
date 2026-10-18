<?php

namespace App\Services\DnsDiagnostics;

/**
 * One reason, note or guidance item of DNS diagnostics (contract `DnsCheckMessage`, feature 010
 * FR-044, research R2): a stable code of `DnsCheckMessageCode`, its parameters, and an English text
 * for logs and scripts. Clients translate from the code and the parameters.
 */
final readonly class DnsCheckMessage
{
    public const SPF_SYNTAX_URL = 'http://www.open-spf.org/SPF_Record_Syntax/';

    public const DMARC_URL = 'https://www.kitterman.com/dmarc/assistant.html';

    public const TLS_REPORTING_URL = 'https://mxtoolbox.com/dmarc/smtp-tls/how-to-setup-smtp-tls-reports';

    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public string $code,
        public array $params,
        public string $message,
    ) {}

    public static function lookupTimeout(int $seconds): self
    {
        return new self('lookupTimeout', ['timeoutSeconds' => $seconds], "The lookup reached the resolver's time limit of {$seconds} seconds.");
    }

    public static function runTimeout(int $seconds): self
    {
        return new self('runTimeout', ['timeoutSeconds' => $seconds], "The run's time limit of {$seconds} seconds was reached before this check could start.");
    }

    public static function lookupError(): self
    {
        return new self('lookupError', [], 'The resolver reported a temporary failure or an error (for example a DNSSEC validation failure).');
    }

    public static function publicAddressUnknown(string $family): self
    {
        return new self('publicAddressUnknown', ['family' => $family], "The server's public {$family} address could not be determined.");
    }

    public static function certificateUnavailable(string $host, int $port, string $cause): self
    {
        return new self('certificateUnavailable', ['host' => $host, 'port' => $port, 'cause' => $cause], "The certificate presented by {$host} on port {$port} could not be read ({$cause}).");
    }

    public static function dkimKeyUnavailable(): self
    {
        return new self('dkimKeyUnavailable', [], "The stored DKIM key could not be read from mailcow's shared store.");
    }

    public static function hostIsAlias(string $target): self
    {
        return new self('hostIsAlias', ['target' => $target], "The server host name is an alias (CNAME) of {$target}; mail exchangers must not be aliases.");
    }

    public static function dkimPublicKeyDiffers(): self
    {
        return new self('dkimPublicKeyDiffers', [], 'The DKIM records found carry another public key than the stored key.');
    }

    public static function spfMultipleRecords(int $count): self
    {
        return new self('spfMultipleRecords', ['count' => $count], "The name publishes {$count} SPF records; only one is allowed.");
    }

    public static function spfTooManyLookups(int $limit): self
    {
        return new self('spfTooManyLookups', ['limit' => $limit], "Evaluating the SPF policy needs more than {$limit} DNS lookups.");
    }

    public static function spfTooManyVoidLookups(int $limit): self
    {
        return new self('spfTooManyVoidLookups', ['limit' => $limit], "Evaluating the SPF policy hits more than {$limit} lookups without an answer.");
    }

    public static function spfNotPass(string $address, string $result): self
    {
        return new self('spfNotPass', ['address' => $address, 'result' => $result], "The SPF policy does not authorise {$address} (result: {$result}).");
    }

    public static function spfError(string $result): self
    {
        return new self('spfError', ['result' => $result], "The SPF policy could not be evaluated ({$result}).");
    }

    /**
     * @param  list<string>  $ipv4
     * @param  list<string>  $ipv6
     */
    public static function matchedByAddressRecords(array $ipv4, array $ipv6): self
    {
        return new self('matchedByAddressRecords', ['ipv4' => $ipv4, 'ipv6' => $ipv6], "An alias was expected; the name publishes the server host name's addresses instead.");
    }

    public static function nullServiceRecord(): self
    {
        return new self('nullServiceRecord', [], 'The name publishes a null service record, which marks the service as not offered.');
    }

    public static function noPublicIpv6(): self
    {
        return new self('noPublicIpv6', [], 'The server has no public IPv6 address: the IPv6 address and reverse name checks are left out.');
    }

    public static function serverChecksOmitted(): self
    {
        return new self('serverChecksOmitted', [], 'The server-wide checks are run for administrators only.');
    }

    public static function propagationDelay(int $hours = 24): self
    {
        return new self('propagationDelay', ['hours' => $hours], "DNS changes can take up to {$hours} hours to show, because resolvers cache records.");
    }

    public static function spfAuthorizeAddresses(?string $ipv4, ?string $ipv6): self
    {
        $addresses = implode(' and ', array_filter([$ipv4 === null ? null : 'ip4:'.$ipv4, $ipv6 === null ? null : 'ip6:'.$ipv6]));

        return new self('spfAuthorizeAddresses', ['ipv4' => $ipv4, 'ipv6' => $ipv6], $addresses === '' ? "The SPF policy must authorise the server's public addresses." : "The SPF policy must authorise {$addresses}.");
    }

    public static function spfSuggestedRecord(string $record): self
    {
        return new self('spfSuggestedRecord', ['record' => $record], "Suggested SPF record: {$record}");
    }

    public static function spfSyntaxReference(): self
    {
        return new self('spfSyntaxReference', ['url' => self::SPF_SYNTAX_URL], 'SPF record syntax: '.self::SPF_SYNTAX_URL);
    }

    public static function dmarcPublishPolicy(): self
    {
        return new self('dmarcPublishPolicy', ['url' => self::DMARC_URL], 'Publish a DMARC policy: '.self::DMARC_URL);
    }

    public static function tlsReportingPublishRecord(): self
    {
        return new self('tlsReportingPublishRecord', ['url' => self::TLS_REPORTING_URL], 'Publish a TLS reporting record: '.self::TLS_REPORTING_URL);
    }

    /**
     * @return array{code: string, params: object, message: string}
     */
    public function toArray(): array
    {
        return ['code' => $this->code, 'params' => (object) $this->params, 'message' => $this->message];
    }
}
