<?php

namespace App\Services\Domains;

/**
 * The usage figures of one domain (contract `DomainUsage`, FR-008), bytes for quotas and storage.
 */
final readonly class DomainUsage
{
    public function __construct(
        public int $mailboxes,
        public int $resources,
        public int $aliases,
        public int $aliasDomains,
        public int $mailboxesLeft,
        public int $aliasesLeft,
        public int $largestMailboxQuota,
        public int $quotaAllocated,
        public int $storageUsed,
        public int $messages,
        public int $maxNewMailboxQuota,
    ) {}

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
