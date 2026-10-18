<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Policies\RoutingPolicy;
use App\Rules\CodedRule;
use App\Services\Domains\DomainScope;
use App\Services\Mailboxes\MailboxScope;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Body of `replaceDomainRelayhostAssignment` and `replaceMailboxRelayhostAssignment`
 * (`RelayhostAssignmentReplaceRequest`, feature 009 FR-026, FR-029): `relayhostId`, required, an
 * integer of at least 1 or `null`; whether the relayhost exists is checked by the service
 * (`notFound`). The role and the permission flag are checked first (`403`), so that a refusal never
 * reveals whether an object outside the scope exists, then the scope (`404`), then the body.
 */
class ReplaceRelayhostAssignmentRequest extends ApiFormRequest
{
    private string $target = '';

    public function authorize(): bool
    {
        $principal = RequestContext::requirePrincipal($this);
        $mailbox = $this->route('mailbox');

        if (is_string($mailbox)) {
            app(RoutingPolicy::class)->authorize($principal, RoutingPolicy::CHANGE_MAILBOX_RELAYHOST_ASSIGNMENT);
            $this->target = app(MailboxScope::class)->resolve($principal, $mailbox) ?? throw new NotFoundHttpException('The mailbox does not exist.');
        } else {
            app(RoutingPolicy::class)->authorize($principal, RoutingPolicy::CHANGE_DOMAIN_RELAYHOST_ASSIGNMENT);
            $this->target = app(DomainScope::class)->resolve($principal, (string) $this->route('domain')) ?? throw new NotFoundHttpException('The domain does not exist.');
        }

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'relayhostId' => ['present', new class extends CodedRule
            {
                protected function check(mixed $value): ?array
                {
                    return match (true) {
                        $value === null => null,
                        ! is_int($value) => $this->violation('invalidType', ['expected' => 'integer']),
                        $value < 1 => $this->violation('outOfRange', ['min' => 1]),
                        default => null,
                    };
                }
            }],
        ];
    }

    /** The domain (ASCII name) or mailbox address of the path, in the caller's scope. */
    public function target(): string
    {
        return $this->target;
    }

    public function relayhostId(): ?int
    {
        $id = $this->validated('relayhostId');

        return is_int($id) ? $id : null;
    }
}
