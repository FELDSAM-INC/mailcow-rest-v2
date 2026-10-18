<?php

namespace App\Services\Dkim;

/**
 * The outcome of a key change (feature 010 research R3): the entry afterwards, whether the name had
 * no key before (`created`, answered `201`) or a key was replaced or published again (`200`), the
 * replaced selector and public key, and the DKIM DNS publication outcome.
 */
final readonly class DkimKeyChange
{
    public function __construct(
        public DkimEntry $entry,
        public bool $created,
        public DkimDnsPublicationResult $publication,
        public ?string $oldSelector = null,
        public ?string $oldPublicKey = null,
    ) {}
}
