<?php

namespace App\Http\Requests\Api\V2\Concerns;

use App\Http\RequestContext;
use App\Services\SelfService\MailboxAccess;

/**
 * The `{mailbox}` of a self-service route, resolved in the caller's scope before the body is
 * validated (feature 005 FR-003: `404` first, then `422`).
 */
trait ResolvesSelfServiceMailbox
{
    private ?string $resolvedMailbox = null;

    public function authorize(): bool
    {
        $this->resolvedMailbox = app(MailboxAccess::class)->resolve(RequestContext::requirePrincipal($this), (string) $this->route('mailbox'));
        $this->afterMailboxResolved();

        return true;
    }

    /**
     * Checks that must run after the mailbox is known and before the body is validated.
     */
    protected function afterMailboxResolved(): void {}

    public function mailbox(): string
    {
        return (string) $this->resolvedMailbox;
    }
}
