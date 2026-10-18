<?php

namespace App\Services\Dkim;

/**
 * A key as stored for a name: selector, public key, whether the private key exists, the private
 * key only as an HMAC (for the version tag) and the internal fingerprint of the compare-and-set
 * (never returned or logged).
 */
final readonly class StoredDkimKey
{
    public function __construct(
        public string $name,
        public ?string $selector,
        public ?string $publicKey,
        public bool $hasPrivateKey,
        public ?string $privateKeyHmac,
        public string $fingerprint,
    ) {}

    public function complete(): bool
    {
        return $this->selector !== null && $this->publicKey !== null && $this->hasPrivateKey;
    }
}
