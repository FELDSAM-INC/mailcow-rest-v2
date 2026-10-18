<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\MailboxValueRules;
use App\Models\Mailcow\Mailbox;
use App\Services\Mailboxes\MailboxValues;
use Illuminate\Support\Facades\Gate;

/**
 * Body of `createMailbox` (`MailboxCreateRequest`, feature 003 FR-018): the contract's types,
 * bounds and enumerations; members the schema does not define, such as the recovery e-mail, custom
 * attributes, sender permissions or the relayhost, are refused (`immutable`). The business rules
 * are MailboxValidator's. Administrators and domain administrators; the policy runs first.
 */
class StoreMailboxRequest extends ApiFormRequest
{
    use MailboxValueRules;

    public const MEMBERS = [
        'localPart', 'domain', 'password', 'displayName', 'authSource', 'templateId', 'quota', 'status',
        'forcePasswordChange', 'forceSecondFactorSetup', 'webmailRedirect', 'protocolAccess', 'userPermissions',
        'tags', 'taggedMailHandling', 'quarantineNotification', 'quarantineCategory', 'tlsEnforceInbound',
        'tlsEnforceOutbound', 'rateLimit',
    ];

    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows('create', Mailbox::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'localPart' => ['bail', 'required', 'string', 'max:255'],
            'domain' => ['bail', 'required', 'string', 'max:255'],
            'password' => ['bail', 'sometimes', 'string', 'min:1', 'max:255'],
            'displayName' => ['bail', 'sometimes', 'string', 'max:255'],
            'authSource' => ['bail', 'sometimes', 'string', 'in:'.implode(',', array_keys(MailboxValues::AUTH_SOURCES))],
            'templateId' => ['bail', 'sometimes', 'integer', 'min:1'],
            ...$this->valueRules(self::MEMBERS),
        ];
    }

    protected function memberViolations(array $body): array
    {
        return $this->unknownMembers($body, self::MEMBERS);
    }
}
