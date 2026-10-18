<?php

namespace App\Clients\SharedStore;

/**
 * Answer of one DKIM key script run: `ok`, `exists`, `absent` or `outdated`, with the selector,
 * public key and private key the change replaced or removed (null when there was none). The old
 * private key stays in memory only, for the audit compensation of feature 010 research R13; it is
 * hidden from debug output.
 */
final readonly class DkimStoreResult
{
    public function __construct(
        public string $status,
        public ?string $oldSelector = null,
        public ?string $oldPublicKey = null,
        #[\SensitiveParameter] public ?string $oldPrivateKey = null,
    ) {}

    public function ok(): bool
    {
        return $this->status === 'ok';
    }

    /**
     * @return array<string, string|null>
     */
    public function __debugInfo(): array
    {
        return ['status' => $this->status, 'oldSelector' => $this->oldSelector, 'oldPublicKey' => $this->oldPublicKey, 'oldPrivateKey' => $this->oldPrivateKey === null ? null : '***'];
    }
}
