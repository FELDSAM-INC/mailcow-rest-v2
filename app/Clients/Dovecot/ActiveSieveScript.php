<?php

namespace App\Clients\Dovecot;

/**
 * The Sieve script that is active for a mailbox in the mail server (the one the user manages in
 * webmail); both null when none is active (feature 005 FR-053).
 */
final readonly class ActiveSieveScript
{
    public function __construct(public ?string $name, public ?string $script) {}

    public static function none(): self
    {
        return new self(null, null);
    }
}
