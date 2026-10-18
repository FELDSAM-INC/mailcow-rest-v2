<?php

namespace App\Exceptions;

use App\Support\Http\ProblemType;
use RuntimeException;

/**
 * Ends a request with a documented problem type. Rendered by App\Exceptions\ProblemRenderer and
 * never reported as an error.
 */
class ProblemException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $extensions  extension members (for example `retryAfter`)
     * @param  array<string, string>  $headers  response headers (for example `Retry-After`)
     */
    public function __construct(
        public readonly ProblemType $type,
        public readonly ?string $detail = null,
        public readonly array $extensions = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($detail ?? $type->title());
    }
}
