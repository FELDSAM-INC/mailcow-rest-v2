<?php

namespace App\Services\IdentityProvider;

use Carbon\CarbonImmutable;

/**
 * The status of the user import and periodic full synchronisation of a Keycloak or LDAP provider
 * (contract `IdentityProviderSynchronisation`, FR-062), derived read-only from the legacy jobs'
 * scheduled-job log. Runtime status: never part of a version tag.
 */
final readonly class SynchronisationStatus
{
    public function __construct(
        public bool $enabled,
        public string $state,
        public ?CarbonImmutable $lastCompletedAt = null,
        public ?CarbonImmutable $lastFailedAt = null,
        public ?CarbonImmutable $nextRunExpectedAt = null,
    ) {}

    public static function disabled(): self
    {
        return new self(false, 'disabled');
    }

    /**
     * @return array{enabled: bool, state: string, lastCompletedAt: string|null, lastFailedAt: string|null, nextRunExpectedAt: string|null}
     */
    public function toArray(): array
    {
        $format = fn (?CarbonImmutable $t): ?string => $t?->utc()->format('Y-m-d\TH:i:s\Z');

        return [
            'enabled' => $this->enabled,
            'state' => $this->state,
            'lastCompletedAt' => $format($this->lastCompletedAt),
            'lastFailedAt' => $format($this->lastFailedAt),
            'nextRunExpectedAt' => $format($this->nextRunExpectedAt),
        ];
    }
}
