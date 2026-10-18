<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\MailboxValueRules;
use App\Models\Mailcow\MailboxTemplate;
use Illuminate\Support\Facades\Gate;

/**
 * Body of `updateMailboxTemplate` (`MailboxTemplatePatch`, JSON Merge Patch, feature 003 FR-052): at
 * least one member, the contract's types and bounds; unknown members are `immutable`.
 * Administrators only; the policy runs first.
 */
class UpdateMailboxTemplateRequest extends ApiFormRequest
{
    use MailboxValueRules;

    protected bool $requiresNonEmptyBody = true;

    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows('update', MailboxTemplate::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'sometimes', 'string', 'max:255'],
            ...$this->valueRules(StoreMailboxTemplateRequest::MEMBERS),
        ];
    }

    protected function memberViolations(array $body): array
    {
        return $this->unknownMembers($body, StoreMailboxTemplateRequest::MEMBERS);
    }
}
