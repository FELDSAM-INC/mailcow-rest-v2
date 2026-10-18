<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\MailboxValueRules;
use App\Models\Mailcow\MailboxTemplate;
use Illuminate\Support\Facades\Gate;

/**
 * Body of `createMailboxTemplate` (`MailboxTemplateCreateRequest`, feature 003 FR-050, FR-051): a
 * name and the contract's types and bounds of the values; unknown members are `immutable`.
 * Administrators only; the policy runs first.
 */
class StoreMailboxTemplateRequest extends ApiFormRequest
{
    use MailboxValueRules;

    public const MEMBERS = [
        'name', 'quota', 'status', 'forcePasswordChange', 'forceSecondFactorSetup', 'webmailRedirect', 'protocolAccess',
        'userPermissions', 'tags', 'taggedMailHandling', 'quarantineNotification', 'quarantineCategory',
        'tlsEnforceInbound', 'tlsEnforceOutbound', 'rateLimit',
    ];

    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows('create', MailboxTemplate::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'max:255'],
            ...$this->valueRules(self::MEMBERS),
        ];
    }

    protected function memberViolations(array $body): array
    {
        return $this->unknownMembers($body, self::MEMBERS);
    }
}
