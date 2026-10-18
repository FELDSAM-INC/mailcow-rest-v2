<?php

namespace App\Clients\SharedStore;

/**
 * Answer of one DKIM key script run: `ok`, `exists`, `absent` or `outdated`, with the selector and
 * public key the change replaced (null when there was none).
 */
final readonly class DkimStoreResult
{
    public function __construct(
        public string $status,
        public ?string $oldSelector = null,
        public ?string $oldPublicKey = null,
    ) {}

    public function ok(): bool
    {
        return $this->status === 'ok';
    }
}
