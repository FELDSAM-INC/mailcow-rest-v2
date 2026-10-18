<?php

namespace App\Services\Dkim;

use App\Clients\Ispconfig\IspconfigDnsClient;
use App\Clients\Ispconfig\IspconfigException;
use App\Clients\Ispconfig\IspconfigRecord;
use App\Support\Clock\Clock;
use App\Support\Clock\Deadline;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;

/**
 * The FeldHost fork's dkim_update_in_dns() (feature 002 research D10, feature 010 research R12):
 * keeps the TXT record `<selector>._domainkey` of the ISPConfig zone whose origin is exactly the
 * name in step with the stored key. One session per publication, bounded by
 * RESTV2_ISPCONFIG_TIMEOUT (15 s); every outcome is reported and none is thrown.
 */
class DkimDnsPublisher
{
    private static bool $warnedIncomplete = false;

    public function __construct(
        private readonly Container $container,
        private readonly Clock $clock,
    ) {}

    public function publish(string $name, string $selector, string $publicKey, ?string $oldSelector = null): DkimDnsPublicationResult
    {
        $recordName = $selector.'._domainkey';

        return $this->session($name, $recordName, function (IspconfigDnsClient $client, int $zoneId, int $clientId) use ($name, $selector, $publicKey, $oldSelector, $recordName): DkimDnsPublicationResult {
            $records = $client->records($zoneId);
            $staleFailure = null;

            if ($oldSelector !== null && strcasecmp($oldSelector, $selector) !== 0) {
                try {
                    foreach ($this->matching($records, $name, $oldSelector) as $stale) {
                        $client->deleteTxt($stale->id);
                    }
                } catch (IspconfigException $e) {
                    $staleFailure = $e;
                }
            }

            $params = [
                'server_id' => IspconfigSettings::fromConfig()->dnsServerId,
                'zone' => $zoneId,
                'name' => $recordName,
                'type' => 'TXT',
                'data' => (new DkimPublicKey($publicKey))->recordValue(),
                'ttl' => 7200,
                'active' => 'Y',
                'stamp' => $this->clock->now()->setTimezone((string) (config('restv2.timezone') ?: 'UTC'))->format('Y-m-d H:i:s'),
            ];

            $current = $this->matching($records, $name, $selector);

            if ($current === []) {
                $client->addTxt($clientId, $params);
            } else {
                $client->updateTxt($clientId, $current[0]->id, $params);

                foreach (array_slice($current, 1) as $duplicate) {
                    $client->deleteTxt($duplicate->id);
                }
            }

            return $staleFailure === null
                ? new DkimDnsPublicationResult(DkimDnsOutcome::Published, $recordName)
                : new DkimDnsPublicationResult(DkimDnsOutcome::Failed, $recordName, 'staleRecordRemovalFailed', IspconfigSettings::fromConfig()->scrub($staleFailure->detail));
        });
    }

    public function remove(string $name, string $selector): DkimDnsPublicationResult
    {
        $recordName = $selector.'._domainkey';

        return $this->session($name, $recordName, function (IspconfigDnsClient $client, int $zoneId) use ($name, $selector, $recordName): DkimDnsPublicationResult {
            $records = $this->matching($client->records($zoneId), $name, $selector);

            foreach ($records as $record) {
                $client->deleteTxt($record->id);
            }

            return new DkimDnsPublicationResult($records === [] ? DkimDnsOutcome::NoRecordToRemove : DkimDnsOutcome::Removed, $recordName);
        });
    }

    /**
     * @param  callable(IspconfigDnsClient, int, int): DkimDnsPublicationResult  $work  zone ID, client ID
     */
    private function session(string $name, string $recordName, callable $work): DkimDnsPublicationResult
    {
        $settings = IspconfigSettings::fromConfig();

        if (! $settings->enabled()) {
            if ($settings->incomplete() && ! self::$warnedIncomplete) {
                self::$warnedIncomplete = true;
                Log::warning('The DKIM DNS publication is switched on, but the ISPConfig URL, user or password is missing: nothing is published.');
            }

            return new DkimDnsPublicationResult(DkimDnsOutcome::NotConfigured, null);
        }

        $client = $this->container->make(IspconfigDnsClient::class);

        try {
            $client->open(new Deadline($this->clock, $settings->timeout));
            $zone = $client->findZone($name.'.');

            if ($zone === null) {
                return new DkimDnsPublicationResult(DkimDnsOutcome::NoZone, $recordName);
            }

            return $work($client, $zone->id, $zone->sysGroupId - 1);
        } catch (IspconfigException $e) {
            Log::warning("The DKIM record {$recordName} of {$name} was not published to ISPConfig ({$e->reason}).");

            return new DkimDnsPublicationResult(DkimDnsOutcome::Failed, $recordName, $e->reason, $settings->scrub($e->detail));
        } finally {
            $client->close();
        }
    }

    /**
     * TXT records named `<selector>._domainkey`, relative or fully qualified, ignoring case.
     *
     * @param  list<IspconfigRecord>  $records
     * @return list<IspconfigRecord>
     */
    private function matching(array $records, string $name, string $selector): array
    {
        $relative = strtolower($selector.'._domainkey');
        $qualified = $relative.'.'.strtolower($name).'.';

        return array_values(array_filter(
            $records,
            fn (IspconfigRecord $r): bool => $r->type === 'TXT' && in_array(strtolower($r->name), [$relative, $qualified], true),
        ));
    }

    /** Lets tests observe the once-per-process warning again. */
    public static function resetWarnings(): void
    {
        self::$warnedIncomplete = false;
    }
}
