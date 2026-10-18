<?php

namespace App\Services\IdentityProvider;

use App\Auth\IdentityProviderType;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads the synchronisation status of the configured Keycloak or LDAP provider from mailcow's
 * scheduled-job log (Redis list `CRON_LOG` on the `mailcow` connection, newest first, trimmed to
 * LOG_LINES), research R12, FR-062. It reads chunks of 500 entries, newest first, keeping the
 * entries of the job's task, until a completed run is found or 10,000 entries were read. Read on
 * every request, never cached. The jobs themselves are never run or triggered (FR-018).
 */
class SynchronisationStatusReader
{
    public const CHUNK = 500;

    public const MAX_ENTRIES = 10000;

    private const TASKS = ['keycloak' => 'Keycloak Sync', 'ldap' => 'LDAP Sync'];

    public function __construct(private readonly RedisFactory $redis) {}

    /**
     * The status, or null for a generic OIDC provider (no synchronisation).
     */
    public function read(IdentityProviderConfig $config, CarbonImmutable $now): ?SynchronisationStatus
    {
        if (! $config->type->offersSynchronisation()) {
            return null;
        }

        if (! $config->get('importUsers') && ! $config->get('periodicSync')) {
            return SynchronisationStatus::disabled();
        }

        $task = self::TASKS[$config->type === IdentityProviderType::Keycloak ? 'keycloak' : 'ldap'];
        $status = SynchronisationLogParser::derive($this->entries($task), $task, (int) $config->get('syncInterval'), $now);

        return new SynchronisationStatus(true, $status['state'], $status['lastCompletedAt'], $status['lastFailedAt'], $status['nextRunExpectedAt']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function entries(string $task): array
    {
        $entries = [];

        try {
            $connection = $this->redis->connection('mailcow');

            for ($start = 0; $start < self::MAX_ENTRIES; $start += self::CHUNK) {
                $chunk = $connection->command('lrange', ['CRON_LOG', $start, $start + self::CHUNK - 1]);

                if (! is_array($chunk) || $chunk === []) {
                    break;
                }

                $completed = false;

                foreach ($chunk as $raw) {
                    $entry = json_decode((string) $raw, true);

                    if (is_array($entry) && ($entry['task'] ?? null) === $task) {
                        $entries[] = $entry;
                        $completed = $completed || SynchronisationLogParser::classify($entry) === SynchronisationLogParser::COMPLETED;
                    }
                }

                if ($completed || count($chunk) < self::CHUNK) {
                    break;
                }
            }
        } catch (Throwable $e) {
            Log::warning('The scheduled-job log could not be read: '.$e->getMessage());
        }

        return $entries;
    }
}
