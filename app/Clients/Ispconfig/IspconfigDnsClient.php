<?php

namespace App\Clients\Ispconfig;

use App\Support\Clock\Deadline;

/**
 * The DNS functions of an ISPConfig server's remote API, as the FeldHost fork's
 * dkim_update_in_dns() uses them (feature 002 research D10, feature 010 research R11). One
 * session per publication: open() signs in under a deadline that bounds every call, close() signs
 * out and never throws. Every failure raises IspconfigException with a reason code. Faked in tests.
 */
interface IspconfigDnsClient
{
    public function open(Deadline $deadline): void;

    /** The zone whose origin is exactly the given one (with the trailing dot), or null. */
    public function findZone(string $origin): ?IspconfigZone;

    /**
     * @return list<IspconfigRecord>
     */
    public function records(int $zoneId): array;

    /**
     * @param  array<string, mixed>  $params
     */
    public function addTxt(int $clientId, array $params): void;

    /**
     * @param  array<string, mixed>  $params
     */
    public function updateTxt(int $clientId, int $recordId, array $params): void;

    public function deleteTxt(int $recordId): void;

    public function close(): void;
}
