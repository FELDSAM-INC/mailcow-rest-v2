<?php

namespace Tests\Support\Contract;

use stdClass;

/**
 * A contract operation matched to a request.
 */
final readonly class MatchedOperation
{
    /**
     * @param  array<string, string>  $pathParameters
     */
    public function __construct(
        public string $operationId,
        public string $method,
        public string $pathTemplate,
        public array $pathParameters,
        public stdClass $operation,
        public stdClass $pathItem,
    ) {}
}
