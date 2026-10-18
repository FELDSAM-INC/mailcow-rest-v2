<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\MailboxValueRules;
use App\Models\Mailcow\Mailbox;
use App\Services\Mailboxes\MailboxScope;
use App\Services\Mailboxes\MailboxValues;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Body of `updateMailbox` (`MailboxPatch`, JSON Merge Patch, feature 003 FR-029): at least one member
 * (else `required` on `body`) and the types and bounds of the changeable members. Every other member
 * of the mailbox representation, and any unknown member, is refused (`immutable`). The role is
 * checked first (`403`), then the scope (`404`), then the body (`422`).
 */
class UpdateMailboxRequest extends ApiFormRequest
{
    use MailboxValueRules;

    public const MEMBERS = [
        'displayName', 'status', 'quota', 'password', 'forcePasswordChange', 'forceSecondFactorSetup', 'webmailRedirect',
        'protocolAccess', 'authSource', 'recoveryEmail', 'tags', 'userPermissions', 'customAttributes',
    ];

    protected bool $requiresNonEmptyBody = true;

    private ?string $address = null;

    public function authorize(): bool
    {
        $principal = RequestContext::requirePrincipal($this);

        if (! Gate::forUser($principal)->allows('update', Mailbox::class)) {
            return false;
        }

        $this->address = app(MailboxScope::class)->resolve($principal, (string) $this->route('mailbox'))
            ?? throw new NotFoundHttpException('The mailbox does not exist.');

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'displayName' => ['bail', 'sometimes', 'string', 'max:255'],
            'password' => ['bail', 'sometimes', 'string', 'min:1', 'max:255'],
            'authSource' => ['bail', 'sometimes', 'string', 'in:'.implode(',', array_keys(MailboxValues::AUTH_SOURCES))],
            'recoveryEmail' => ['bail', 'sometimes', 'nullable', 'string', 'max:255'],
            'customAttributes' => ['bail', 'sometimes', 'array', 'min:1'],
            ...$this->valueRules(self::MEMBERS),
        ];
    }

    protected function memberViolations(array $body): array
    {
        return $this->unknownMembers($body, self::MEMBERS);
    }

    /** The resolved address of the mailbox in the caller's scope. */
    public function address(): string
    {
        return (string) $this->address;
    }
}
