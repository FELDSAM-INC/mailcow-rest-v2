<?php

namespace App\Clients\Smtp;

use RuntimeException;

/**
 * A step of the connection test failed, with the contract's failure reason (internal to
 * StreamSmtpProbe; the probe returns it as its result).
 */
final class SmtpStepFailure extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
