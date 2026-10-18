<?php

namespace App\Clients\Development;

use App\Clients\Ispconfig\IspconfigDnsClient;
use App\Clients\Ispconfig\IspconfigZone;
use App\Support\Clock\Deadline;

/**
 * Development stand-in for an ISPConfig server without zones (RESTV2_FAKE_OUTBOUND=y,
 * APP_ENV=local): an enabled publication reports `noZone`.
 */
final class NullIspconfigDnsClient implements IspconfigDnsClient
{
    public function open(Deadline $deadline): void {}

    public function findZone(string $origin): ?IspconfigZone
    {
        return null;
    }

    public function records(int $zoneId): array
    {
        return [];
    }

    public function addTxt(int $clientId, array $params): void {}

    public function updateTxt(int $clientId, int $recordId, array $params): void {}

    public function deleteTxt(int $recordId): void {}

    public function close(): void {}
}
