<?php

namespace App\Services\MailQueue;

use Carbon\CarbonImmutable;

/**
 * One queue listing request (feature 011 FR-038).
 */
final readonly class MailQueueQuery
{
    /**
     * @param  list<string>  $queues
     * @param  list<string>  $senders
     * @param  list<string>  $recipients
     */
    public function __construct(
        public int $limit = 25,
        public int $offset = 0,
        public string $sort = 'arrivalTime',
        public string $order = 'asc',
        public ?string $search = null,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
        public array $queues = [],
        public array $senders = [],
        public array $recipients = [],
    ) {}
}
