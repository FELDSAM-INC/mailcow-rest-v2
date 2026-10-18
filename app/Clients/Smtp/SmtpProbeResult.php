<?php

namespace App\Clients\Smtp;

use JsonSerializable;

/**
 * The outcome of a connection test (contract `RoutingConnectionTestResult` without the parts the
 * caller knows): whether the message was accepted, the step reached or failed, the failure reason
 * and its English message, whether TLS was used, the login state, the last server reply and the
 * transcript with login data already redacted.
 */
final readonly class SmtpProbeResult implements JsonSerializable
{
    /**
     * @param  list<array{direction: string, text: string}>  $transcript
     */
    public function __construct(
        public bool $accepted,
        public string $step,
        public ?string $failureReason,
        public ?string $failureMessage,
        public bool $tls,
        public string $login,
        public ?int $lastReplyCode,
        public ?string $lastReplyText,
        public array $transcript,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
