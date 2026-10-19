<?php

namespace App\Services\SpamPolicy;

use App\Auth\Principal;
use App\Clients\Rspamd\RspamdController;
use App\Clients\Rspamd\RspamdUnavailable;
use App\Exceptions\ProblemException;
use App\Policies\SpamPolicyPolicy;
use Carbon\CarbonImmutable;

/**
 * The spam filter's action statistics (feature 006 research R14, FR-058), as the legacy dashboard
 * chart shows them (`rspamd/stat`): `reject` → rejected, `soft reject` plus `greylist` →
 * soft-rejected, `rewrite subject`, `add header` → moved to junk, `no action`; a missing action
 * counts 0.
 *
 * `countedSince` is a numeric `start_time` the filter may report (Rspamd `2026-09a` reports none:
 * its counters survive restarts in its stats file), else null. `spamFilterRunningSince` is now
 * minus the reported `uptime`, else null. The controller is asked with legacy's 30 seconds
 * (FR-062); a failure is `503 temporary-failure` with `dependency: spamFilter`. Not audited.
 */
class SpamFilterStatisticsService
{
    private const ACTIONS = [
        'rejected' => ['reject'],
        'softRejected' => ['soft reject', 'greylist'],
        'subjectRewritten' => ['rewrite subject'],
        'movedToJunk' => ['add header'],
        'noAction' => ['no action'],
    ];

    public function __construct(
        private readonly SpamPolicyPolicy $policy,
        private readonly RspamdController $rspamd,
    ) {}

    /**
     * @return array<string, int|string|null> the contract `SpamFilterActionStatistics`
     */
    public function statistics(Principal $principal): array
    {
        $this->policy->authorize($principal, SpamPolicyPolicy::VIEW_STATISTICS);

        try {
            $stat = $this->rspamd->stat(max(1, (int) config('restv2.rspamd.timeout', 30)));
        } catch (RspamdUnavailable) {
            throw ProblemException::temporaryFailure('spamFilter', null, 'The spam filter could not be reached; retry later.');
        }

        $actions = is_array($stat['actions'] ?? null) ? $stat['actions'] : [];
        $counts = [];

        foreach (self::ACTIONS as $member => $names) {
            $counts[$member] = array_sum(array_map(fn (string $name): int => is_numeric($actions[$name] ?? null) ? max(0, (int) $actions[$name]) : 0, $names));
        }

        $uptime = $stat['uptime'] ?? null;
        $start = $stat['start_time'] ?? null;
        $now = CarbonImmutable::now('UTC');

        return $counts + [
            'total' => array_sum($counts),
            'countedSince' => is_numeric($start) ? CarbonImmutable::createFromTimestampUTC((int) $start)->format('Y-m-d\TH:i:s\Z') : null,
            'spamFilterRunningSince' => is_numeric($uptime) ? $now->subSeconds(max(0, (int) $uptime))->format('Y-m-d\TH:i:s\Z') : null,
        ];
    }
}
