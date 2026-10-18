<?php

namespace App\Services\Rspamd;

use App\Clients\Rspamd\RspamdController;
use App\Clients\Rspamd\RspamdUnavailable;
use Carbon\CarbonImmutable;

/**
 * Rspamd's statistics (feature 011 research R16, FR-035, legacy get_logs('rspamd-stats')): the
 * documented subset of the controller's `/stat` (missing numbers are 0, missing maps empty);
 * nothing else is passed through.
 */
class RspamdStatisticsService
{
    public function __construct(private readonly RspamdController $rspamd) {}

    /**
     * @return array<string, mixed>
     */
    public function statistics(): array
    {
        try {
            $stat = $this->rspamd->stat();
        } catch (RspamdUnavailable) {
            throw RspamdHistoryService::unavailable();
        }

        $number = fn (string $key): int => max(0, (int) ($stat[$key] ?? 0));
        $counts = fn (mixed $map): object => (object) (is_array($map) ? array_map(fn (mixed $v): int => max(0, (int) $v), array_filter($map, 'is_numeric')) : []);
        $statfiles = [];

        foreach (is_array($stat['statfiles'] ?? null) ? $stat['statfiles'] : [] as $file) {
            if (is_array($file)) {
                $statfiles[] = [
                    'symbol' => (string) ($file['symbol'] ?? ''),
                    'type' => (string) ($file['type'] ?? ''),
                    'revision' => max(0, (int) ($file['revision'] ?? 0)),
                    'users' => max(0, (int) ($file['users'] ?? 0)),
                ];
            }
        }

        return [
            'scanned' => $number('scanned'),
            'learned' => $number('learned'),
            'spamCount' => $number('spam_count'),
            'hamCount' => $number('ham_count'),
            'actions' => $counts($stat['actions'] ?? null),
            'connections' => $number('connections'),
            'controlConnections' => $number('control_connections'),
            'statfiles' => $statfiles,
            'fuzzyHashes' => $counts($stat['fuzzy_hashes'] ?? null),
            'collectedAt' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
