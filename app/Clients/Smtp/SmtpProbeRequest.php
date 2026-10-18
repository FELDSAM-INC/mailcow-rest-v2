<?php

namespace App\Clients\Smtp;

/**
 * One connection test (feature 009 research R13): the host and its resolved addresses (tried in
 * order), the port, the `EHLO` name, the credentials (no login without a username), the envelope
 * addresses and the deadlines in seconds (per network step, and for the whole dialogue).
 */
final readonly class SmtpProbeRequest
{
    /**
     * @param  list<string>  $addresses  IP addresses of the host
     */
    public function __construct(
        public string $host,
        public array $addresses,
        public int $port,
        public string $heloName,
        public ?string $username,
        #[\SensitiveParameter] public ?string $password,
        public string $sender,
        public string $recipient,
        public float $stepTimeout,
        public float $totalTimeout,
    ) {}

    /**
     * The request without its password, for logs and fakes.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['host' => $this->host, 'addresses' => $this->addresses, 'port' => $this->port, 'heloName' => $this->heloName, 'username' => $this->username, 'sender' => $this->sender, 'recipient' => $this->recipient];
    }
}
