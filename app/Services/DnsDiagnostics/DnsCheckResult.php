<?php

namespace App\Services\DnsDiagnostics;

/**
 * One record check of a run (contract `DnsCheck`, feature 010 FR-044): its stable identifier, scope,
 * record name and type, status, optional flag, the expected value in comparison form (or null),
 * every value found, guidance, a reason and notes.
 */
final readonly class DnsCheckResult
{
    public const MATCH = 'match';

    public const MISMATCH = 'mismatch';

    public const MISSING = 'missing';

    public const LOOKUP_FAILED = 'lookupFailed';

    public const INFORMATIONAL = 'informational';

    public const EXPECTED_UNKNOWN = 'expectedUnknown';

    public const STATUSES = [self::MATCH, self::MISMATCH, self::MISSING, self::LOOKUP_FAILED, self::INFORMATIONAL, self::EXPECTED_UNKNOWN];

    /**
     * @param  list<string>  $found
     * @param  list<DnsCheckMessage>  $guidance
     * @param  list<DnsCheckMessage>  $notes
     */
    public function __construct(
        public string $id,
        public string $scope,
        public string $recordName,
        public string $recordType,
        public string $status,
        public bool $optional,
        public ?string $expected,
        public array $found,
        public array $guidance,
        public ?DnsCheckMessage $reason,
        public array $notes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'scope' => $this->scope,
            'recordName' => $this->recordName,
            'recordType' => $this->recordType,
            'status' => $this->status,
            'optional' => $this->optional,
            'expected' => $this->expected,
            'found' => $this->found,
            'guidance' => array_map(fn (DnsCheckMessage $m): array => $m->toArray(), $this->guidance),
            'reason' => $this->reason?->toArray(),
            'notes' => array_map(fn (DnsCheckMessage $m): array => $m->toArray(), $this->notes),
        ];
    }
}
