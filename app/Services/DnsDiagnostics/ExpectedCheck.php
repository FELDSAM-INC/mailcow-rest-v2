<?php

namespace App\Services\DnsDiagnostics;

/**
 * One check a run will make (feature 010 FR-040, FR-041, research R17): its identifier, scope,
 * record name and type, the expected value in comparison form (null for guidance-only checks and
 * when it is unknown), the comparison to apply, its guidance, the reason why the expected value is
 * unknown, and comparison data (the DKIM public key, the MTA-STS policy identifier, the SRV target
 * and port).
 *
 * POP3 and POP3S are looked up even on their standard ports: a null service record (target `.`)
 * there is the expected value (`nullServiceRecordAllowed`); without one, a check on a standard port
 * is left out of the result (`onlyWithNullServiceRecord`), as legacy does.
 */
final readonly class ExpectedCheck
{
    public const SERVER = 'server';

    public const DOMAIN = 'domain';

    /**
     * @param  list<DnsCheckMessage>  $guidance
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $id,
        public string $scope,
        public string $recordName,
        public string $recordType,
        public string $comparison,
        public ?string $expected,
        public bool $optional = false,
        public array $guidance = [],
        public ?DnsCheckMessage $unknownReason = null,
        public array $data = [],
        public bool $nullServiceRecordAllowed = false,
        public bool $onlyWithNullServiceRecord = false,
    ) {}
}
