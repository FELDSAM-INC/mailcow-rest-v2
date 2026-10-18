<?php

namespace App\Http\Requests\Api\V2;

use App\Auth\Role;
use App\Http\RequestContext;
use App\Rules\CharacterLength;

/**
 * Body of `validateSieveScript` (`SieveScriptValidationRequest`, feature 005 FR-052):
 * administrators and domain administrators (`403 forbidden` for mailbox users); a script of
 * 1–65,535 bytes. The syntax is the answer, not a violation.
 */
class ValidateSieveScriptRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return RequestContext::requirePrincipal($this)->role !== Role::MailboxUser;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'script' => ['bail', 'present', 'string', new CharacterLength(1, 65535, true)],
        ];
    }

    protected function memberViolations(array $body): array
    {
        return StoreSieveFilterRequest::unknownMembersOf($body, ['script']);
    }

    public function script(): string
    {
        return (string) $this->input('script');
    }
}
