<?php

namespace App\Services\Monitoring;

use App\Clients\SharedStore\SharedStore;
use App\Clients\SharedStore\SharedStoreUnavailable;
use Redis;

/**
 * The monitoring capabilities (feature 011 research R32, FR-069): the IP check switch, the polling
 * interval and maximum ages, the retention of each log, the caller's address and the limits. Needs
 * no container service; mailcow's shared store answers the IP check.
 */
class MonitoringCapabilitiesService
{
    private const IN_MEMORY_LOGS = ['postfix', 'dovecot', 'sogo', 'netfilter', 'watchdog', 'acme', 'cron', 'rateLimits', 'legacyApiRequests'];

    public function __construct(private readonly SharedStore $store) {}

    /**
     * Whether the IP check of the legacy customisation settings is enabled (Redis `IP_CHECK` = 1).
     *
     * @throws SharedStoreUnavailable
     */
    public function ipCheckEnabled(): bool
    {
        return (string) $this->store->run(fn (Redis $redis): mixed => $redis->get('IP_CHECK')) === '1';
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SharedStoreUnavailable
     */
    public function capabilities(string $clientAddress): array
    {
        $entries = max(1, (int) config('restv2.log_lines', 9999) + 1);
        $retention = [];

        foreach (self::IN_MEMORY_LOGS as $log) {
            $retention[$log] = ['policy' => 'entries', 'maxEntries' => $entries];
        }

        $retention['autodiscover'] = ['policy' => 'entries', 'maxEntries' => 101];
        $retention['legacyUiActivity'] = ['policy' => 'unbounded', 'maxEntries' => null];
        $retention['logins'] = ['policy' => 'cleanedDaily', 'maxEntries' => null];

        return [
            'ipCheckEnabled' => $this->ipCheckEnabled(),
            'pollIntervalSeconds' => (int) config('restv2.monitoring.poll_interval', 5),
            'maxAgeSeconds' => [
                'containerStates' => (int) config('restv2.monitoring.max_age.containers', 5),
                'hostStatistics' => (int) config('restv2.monitoring.max_age.host', 10),
                'containerResourceUsage' => (int) config('restv2.monitoring.max_age.usage_sample', 15),
                'mailStorage' => (int) config('restv2.monitoring.max_age.mail_storage', 60),
            ],
            'logRetention' => $retention,
            'clientAddress' => $clientAddress,
            'limits' => [
                'defaultPageSize' => 25,
                'maxPageSize' => 1000,
                'mailQueueBulkMaxIds' => (int) config('restv2.monitoring.queue_bulk_max', 1000),
                'mailQueueListingMax' => (int) config('restv2.monitoring.queue_listing_max', 10000),
                'fail2banRulesMax' => (int) config('restv2.monitoring.rules_max', 100),
                'fail2banRuleMaxLength' => (int) config('restv2.monitoring.rule_max_length', 1000),
                'fail2banListMaxEntries' => (int) config('restv2.monitoring.ban_list_max_entries', 10000),
            ],
        ];
    }
}
