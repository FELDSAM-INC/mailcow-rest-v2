<?php

namespace App\Services\Dkim;

/**
 * A generated or imported key pair: the public key text of `DKIM_PUB_KEYS` (base64 DER without
 * armour) and the private key PEM of `DKIM_PRIV_KEYS`. The private key never appears in debug
 * output, logs or responses (except the gated export of feature 010).
 */
final class DkimKeyMaterial
{
    public function __construct(
        public readonly string $publicKey,
        #[\SensitiveParameter] public readonly string $privateKey,
    ) {}

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['publicKey' => $this->publicKey, 'privateKey' => '***'];
    }
}
