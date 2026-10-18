<?php

namespace App\Services\IdentityProvider;

/**
 * The outcome of a mailbox creation on first sign-in (research R6): created, existing (another
 * sign-in created it meanwhile, FR-040), or failed with the sign-in failure reason
 * (`autoCreationOff`, `noMatchingMapping`, `mailboxCreationFailed`) and, for the latter, its cause
 * (`templateMissing`, `domainNotFound`, `addressInUse`, `mailboxLimitReached`, `quotaExceeded`,
 * `temporaryFailure`, `validationFailed`).
 */
final readonly class ProvisioningResult
{
    private function __construct(
        public string $status,
        public ?string $reason = null,
        public ?string $cause = null,
        public ?string $template = null,
    ) {}

    public static function created(string $template): self
    {
        return new self('created', null, null, $template);
    }

    public static function existing(string $template): self
    {
        return new self('existing', null, null, $template);
    }

    public static function failed(string $reason, ?string $cause = null, ?string $template = null): self
    {
        return new self('failed', $reason, $cause, $template);
    }

    public function isFailure(): bool
    {
        return $this->status === 'failed';
    }

    public function isCreated(): bool
    {
        return $this->status === 'created';
    }
}
