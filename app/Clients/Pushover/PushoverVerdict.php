<?php

namespace App\Clients\Pushover;

/**
 * Pushover's verdict on a token and user key (feature 005 FR-057): accepted, rejected with
 * Pushover's reason, or unavailable (unreachable, timeout, server error).
 */
final readonly class PushoverVerdict
{
    private function __construct(
        public bool $accepted,
        public ?string $reason,
        public bool $unavailable,
    ) {}

    public static function accepted(): self
    {
        return new self(true, null, false);
    }

    public static function rejected(?string $reason): self
    {
        return new self(false, $reason, false);
    }

    public static function unavailable(): self
    {
        return new self(false, null, true);
    }
}
