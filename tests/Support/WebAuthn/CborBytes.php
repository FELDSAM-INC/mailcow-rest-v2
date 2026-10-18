<?php

namespace Tests\Support\WebAuthn;

/**
 * A CBOR byte string (major type 2), as opposed to a text string.
 */
final readonly class CborBytes
{
    public function __construct(public string $bytes) {}
}
