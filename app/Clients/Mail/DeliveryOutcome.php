<?php

namespace App\Clients\Mail;

/**
 * The classified answer of the mail server to a release (feature 006 research R3): `accepted`
 * (`250` after the data), `refused` (any `5xx` reply, with its code and the full reply) or
 * `temporaryFailure` (connection refused, DNS failure, timeout or a `4xx` reply, with the reason).
 */
final readonly class DeliveryOutcome
{
    private function __construct(
        public string $kind,
        public ?int $code = null,
        public ?string $reply = null,
        public ?string $reason = null,
    ) {}

    public static function accepted(): self
    {
        return new self('accepted');
    }

    public static function refused(int $code, string $reply): self
    {
        return new self('refused', $code, $reply);
    }

    public static function temporaryFailure(string $reason): self
    {
        return new self('temporaryFailure', reason: $reason);
    }

    public function isAccepted(): bool
    {
        return $this->kind === 'accepted';
    }

    public function isRefused(): bool
    {
        return $this->kind === 'refused';
    }

    public function isTemporaryFailure(): bool
    {
        return $this->kind === 'temporaryFailure';
    }
}
