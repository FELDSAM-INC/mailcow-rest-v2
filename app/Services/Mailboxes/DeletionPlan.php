<?php

namespace App\Services\Mailboxes;

/**
 * What deleting one mailbox removes (feature 003 FR-041, FR-065), computed by MailboxDeletionPlanner
 * for the deletion's audit record and for the preview.
 */
final readonly class DeletionPlan
{
    /**
     * @param  array{count: int, addresses: list<string>}  $exclusiveAliases
     * @param  array{count: int, addresses: list<string>}  $sharedAliases
     * @param  array<string, int>  $counts
     */
    public function __construct(
        public array $exclusiveAliases,
        public array $sharedAliases,
        public array $counts,
    ) {}

    /**
     * The `removed` details of the deletion's audit record.
     *
     * @return array<string, int>
     */
    public function removed(): array
    {
        return ['exclusiveAliases' => $this->exclusiveAliases['count'], 'sharedAliases' => $this->sharedAliases['count']] + $this->counts;
    }
}
