<?php

namespace App\Services\MailQueue;

/**
 * The result of a queue action (contract `BulkResult`), and whether its audit record was written.
 */
final readonly class MailQueueBulkOutcome
{
    /**
     * @param  array{bulkRequestId: string, results: list<array{id: string, outcome: string}>, summary: array<string, int>}  $result
     */
    public function __construct(public array $result, public bool $auditRecorded) {}
}
