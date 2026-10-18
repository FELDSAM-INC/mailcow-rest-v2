<?php

namespace App\Services\DnsDiagnostics;

use App\Services\Dkim\DkimName;
use Carbon\CarbonImmutable;

/**
 * The result of one DNS diagnostics run (contract `DnsDiagnosticsResult`, feature 010 FR-045):
 * the checked name, the server facts used, the checks with their summary, the result-level notes
 * and the zone file. Never stored.
 */
final readonly class DnsDiagnosticsReport
{
    /**
     * @param  list<DnsCheckResult>  $checks
     * @param  list<DnsCheckMessage>  $notes
     */
    public function __construct(
        public DkimName $name,
        public ServerFacts $facts,
        public ?string $suggestedSpfRecord,
        public CarbonImmutable $checkedAt,
        public bool $serverChecksIncluded,
        public array $checks,
        public array $notes,
        public string $zoneFile,
    ) {}

    /**
     * Number of checks per status.
     *
     * @return array<string, int>
     */
    public function summary(): array
    {
        $summary = array_fill_keys(DnsCheckResult::STATUSES, 0);

        foreach ($this->checks as $check) {
            $summary[$check->status]++;
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name->name,
            'displayName' => $this->name->displayName,
            'aliasDomain' => $this->name->kind === DkimName::ALIAS_DOMAIN,
            'targetDomain' => $this->name->targetDomain,
            'serverHostname' => $this->facts->hostname,
            'publicIpv4' => $this->facts->ipv4,
            'publicIpv6' => $this->facts->ipv6,
            'suggestedSpfRecord' => $this->suggestedSpfRecord,
            'checkedAt' => $this->checkedAt->utc()->toIso8601ZuluString(),
            'serverChecksIncluded' => $this->serverChecksIncluded,
            'summary' => $this->summary(),
            'checks' => array_map(fn (DnsCheckResult $c): array => $c->toArray(), $this->checks),
            'notes' => array_map(fn (DnsCheckMessage $m): array => $m->toArray(), $this->notes),
            'zoneFile' => $this->zoneFile,
        ];
    }
}
