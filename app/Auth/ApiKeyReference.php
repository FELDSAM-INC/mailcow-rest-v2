<?php

namespace App\Auth;

/**
 * The API key a principal authenticated with.
 */
final readonly class ApiKeyReference
{
    public function __construct(
        public string $id,
        public string $name,
        public string $prefix,
        /** `readOnly` or `readWrite`. */
        public string $accessLevel,
    ) {}
}
