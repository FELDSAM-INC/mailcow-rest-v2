<?php

namespace App\Services\Dkim;

/**
 * One DKIM entry (feature 010 FR-007, FR-008): a domain, an alias domain or the name of an unused
 * key, with its stored key (null when it has none).
 */
final readonly class DkimEntry
{
    public const VALID = 'valid';

    public const MISSING = 'missing';

    public const UNUSED = 'unused';

    public function __construct(
        public DkimName $name,
        public ?StoredDkimKey $key,
    ) {}

    public function state(): string
    {
        return match (true) {
            $this->key === null => self::MISSING,
            $this->name->isMailDomain() => self::VALID,
            default => self::UNUSED,
        };
    }
}
