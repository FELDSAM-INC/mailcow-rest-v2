<?php

namespace App\Services\Domains;

/**
 * What deleting a domain would currently be blocked by, remove and keep (FR-065, contract
 * `DomainDeletionPreview`), computed by the same rules as the deletion.
 */
final readonly class DomainDeletionPlan
{
    /**
     * @param  array{count: int, addresses: list<string>}  $mailboxes
     * @param  array{count: int, addresses: list<string>}  $resources
     * @param  list<string>  $aliasDomains
     * @param  array{count: int, addresses: list<string>}  $aliases
     * @param  list<string>  $domainAdminsToDelete
     */
    public function __construct(
        public string $domain,
        public array $mailboxes,
        public array $resources,
        public array $aliasDomains,
        public array $aliases,
        public bool $footer,
        public array $domainAdminsToDelete,
        public bool $dkimKeyKept,
        public string $etag,
    ) {}

    public function allowed(): bool
    {
        return $this->mailboxes['count'] === 0 && $this->resources['count'] === 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'domain' => $this->domain,
            'allowed' => $this->allowed(),
            'blockers' => ['mailboxes' => $this->mailboxes, 'resources' => $this->resources],
            'removals' => ['aliasDomains' => $this->aliasDomains, 'aliases' => $this->aliases, 'footer' => $this->footer],
            'domainAdminsToDelete' => $this->domainAdminsToDelete,
            'dkimKeyKept' => $this->dkimKeyKept,
            'etag' => $this->etag,
        ];
    }
}
