<?php

namespace App\Services\Audit;

use App\Auth\Principal;

/**
 * Who causes an audited change, and in which context: the actor, the creation that triggered a
 * follow-up record (`domainCreation`, `aliasDomainCreation`; feature 010 research R10) and the
 * bulk request it belongs to (docs/conventions.md: one audit record per object, carrying the bulk
 * request ID).
 */
final readonly class AuditContext
{
    public function __construct(
        public Principal $actor,
        public ?string $trigger = null,
        public ?string $bulkRequestId = null,
    ) {}

    public function withBulkRequestId(?string $bulkRequestId): self
    {
        return new self($this->actor, $this->trigger, $bulkRequestId);
    }
}
