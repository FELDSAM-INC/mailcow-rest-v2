<?php

namespace App\Services\Dkim;

/**
 * A resolved DKIM entry name (feature 010 research R7): the name as stored in the `DKIM_*` hashes
 * (lower-case ASCII, or for an unused legacy key its exact stored spelling), its readable form,
 * what it is (a domain, an alias domain or neither) and an alias domain's target domain.
 */
final readonly class DkimName
{
    public const DOMAIN = 'domain';

    public const ALIAS_DOMAIN = 'aliasDomain';

    public const NONE = 'none';

    public function __construct(
        public string $name,
        public string $displayName,
        public string $kind,
        public ?string $targetDomain,
    ) {}

    /** A domain or an alias domain (keys of other names are unused). */
    public function isMailDomain(): bool
    {
        return $this->kind !== self::NONE;
    }
}
