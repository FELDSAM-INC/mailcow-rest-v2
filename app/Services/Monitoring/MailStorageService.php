<?php

namespace App\Services\Monitoring;

use App\Clients\DockerApi\DockerApiClient;
use App\Clients\DockerApi\DockerApiUnreachable;
use Carbon\CarbonImmutable;

/**
 * The usage of the disk that holds the mail storage (feature 011 research R8, FR-007, legacy
 * `get/status/vmail`): `df -H /var/vmail` in the Dovecot container through dockerapi's `system df`
 * task, shared by all clients for 60 seconds. A stopped Dovecot container or a failed measurement
 * is reported as unavailable; an unreachable container service is a temporary failure.
 */
class MailStorageService
{
    public function __construct(
        private readonly DockerApiClient $docker,
        private readonly SharedSnapshotCache $cache,
        private readonly ContainerRegistry $containers,
        private readonly DfOutputParser $parser,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws DockerApiUnreachable
     */
    public function usage(): array
    {
        $snapshot = $this->cache->get('mail-storage', (int) config('restv2.monitoring.max_age.mail_storage', 60), 6.0, function (): Snapshot {
            $service = (string) config('restv2.monitoring.dovecot_container', 'dovecot-mailcow');

            if (! ($this->containers->find($service)?->running ?? false)) {
                return new Snapshot($this->parser->parse(null), CarbonImmutable::now('UTC'));
            }

            $result = $this->docker->execText($service, ['cmd' => 'system', 'task' => 'df', 'dir' => '/var/vmail']);

            if ($result->isUnreachable() || $result->isUnknown()) {
                throw new DockerApiUnreachable('The mail storage could not be measured: '.$result->message);
            }

            return new Snapshot($this->parser->parse($result->isSuccess() && is_string($result->data) ? $result->data : null), CarbonImmutable::now('UTC'));
        });

        return (array) $snapshot->payload + ['collectedAt' => $snapshot->collectedAtString()];
    }
}
