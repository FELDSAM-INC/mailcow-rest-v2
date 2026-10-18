<?php

namespace App\Services\Identity;

/**
 * The objects a caller may reach (contract `Scope`, FR-028): every domain for an administrator,
 * the domains of the active assignments for a domain administrator, the own mailbox for a mailbox
 * user.
 */
final readonly class Scope
{
    /**
     * @param  list<string>  $domains
     */
    private function __construct(
        public string $type,
        public array $domains = [],
        public ?string $mailbox = null,
        public ?string $domain = null,
    ) {}

    public static function allDomains(): self
    {
        return new self('allDomains');
    }

    /**
     * @param  list<string>  $domains
     */
    public static function domains(array $domains): self
    {
        return new self('domains', $domains);
    }

    public static function mailbox(string $mailbox, string $domain): self
    {
        return new self('mailbox', [], $mailbox, $domain);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return match ($this->type) {
            'allDomains' => ['type' => 'allDomains'],
            'domains' => ['type' => 'domains', 'domains' => $this->domains],
            default => ['type' => 'mailbox', 'mailbox' => $this->mailbox, 'domain' => $this->domain],
        };
    }
}
