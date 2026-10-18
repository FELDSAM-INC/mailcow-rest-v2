<?php

namespace App\Services\IdentityProvider;

use App\Auth\IdentityProviderType;
use Carbon\CarbonImmutable;

/**
 * The result of a connection test (contract `IdentityProviderConnectionTestResult`, FR-019): a
 * success, or a failure category with the provider's status (HTTP status or LDAP result code) when
 * an answer was received. Never contains a secret.
 */
final readonly class ConnectionTestResult
{
    public const UNREACHABLE = 'unreachable';

    public const TIMED_OUT = 'timedOut';

    public const TLS_CERTIFICATE_REJECTED = 'tlsCertificateRejected';

    public const CREDENTIALS_REJECTED = 'credentialsRejected';

    public const UNEXPECTED_RESPONSE = 'unexpectedResponse';

    public function __construct(
        public IdentityProviderType $type,
        public string $testedAddress,
        public CarbonImmutable $testedAt,
        public ?string $failureCategory,
        public ?int $providerStatus,
    ) {}

    public function succeeded(): bool
    {
        return $this->failureCategory === null;
    }

    /**
     * @return array{type: string, testedAddress: string, testedAt: string, outcome: string, failureCategory: string|null, providerStatus: int|null}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'testedAddress' => $this->testedAddress,
            'testedAt' => $this->testedAt->utc()->format('Y-m-d\TH:i:s\Z'),
            'outcome' => $this->succeeded() ? 'success' : 'failure',
            'failureCategory' => $this->failureCategory,
            'providerStatus' => $this->providerStatus,
        ];
    }
}
