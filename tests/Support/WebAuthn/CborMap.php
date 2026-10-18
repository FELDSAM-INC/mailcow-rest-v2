<?php

namespace Tests\Support\WebAuthn;

/**
 * A CBOR map (major type 5), also when empty.
 */
final readonly class CborMap
{
    /**
     * @param  array<int|string, mixed>  $entries
     */
    public function __construct(public array $entries = []) {}
}
