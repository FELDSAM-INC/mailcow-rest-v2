<?php

namespace App\Services\MailQueue;

/**
 * One reading of Postfix's queue (feature 011 research R17, R33): the first 10,000 messages as
 * contract representations, the number of messages per queue over everything Postfix reported,
 * whether it reported more than the listing holds, and every queue ID (for the per-ID results of
 * the actions).
 */
final readonly class MailQueueListing
{
    public const QUEUES = ['active', 'deferred', 'hold', 'incoming', 'maildrop'];

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  array{active: int, deferred: int, hold: int, incoming: int, maildrop: int, total: int}  $counts
     * @param  array<string, true>  $ids
     */
    public function __construct(
        public array $messages,
        public array $counts,
        public bool $truncated,
        private array $ids,
    ) {}

    public function contains(string $id): bool
    {
        return isset($this->ids[$id]);
    }
}
