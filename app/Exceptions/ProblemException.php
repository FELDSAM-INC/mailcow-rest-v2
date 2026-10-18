<?php

namespace App\Exceptions;

use App\Support\Http\ProblemType;
use RuntimeException;

/**
 * Ends a request with a documented problem type. Rendered by App\Exceptions\ProblemRenderer and
 * never reported as an error. Audit details, when given, go into the refusal's audit record only.
 */
class ProblemException extends RuntimeException implements CarriesAuditDetails
{
    /**
     * @param  array<string, mixed>  $extensions  extension members (for example `retryAfter`)
     * @param  array<string, string>  $headers  response headers (for example `Retry-After`)
     * @param  array<string, mixed>  $auditDetails  further details of the failure record
     */
    public function __construct(
        public readonly ProblemType $type,
        public readonly ?string $detail = null,
        public readonly array $extensions = [],
        public readonly array $headers = [],
        public readonly array $auditDetails = [],
    ) {
        parent::__construct($detail ?? $type->title());
    }

    public function auditDetails(): array
    {
        return $this->auditDetails;
    }
}
