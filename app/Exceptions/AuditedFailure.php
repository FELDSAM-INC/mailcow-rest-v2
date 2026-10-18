<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * An unexpected failure (`500 about:blank`) after an operation already changed something outside
 * the database, whose failure record must say so (feature 003: a mailbox deletion whose database
 * part failed after the mail was moved to the garbage area). The previous exception is reported to
 * the log as usual.
 */
class AuditedFailure extends RuntimeException implements CarriesAuditDetails
{
    /**
     * @param  array<string, mixed>  $auditDetails
     */
    public function __construct(string $message, private readonly array $auditDetails, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function auditDetails(): array
    {
        return $this->auditDetails;
    }
}
