<?php

namespace App\Http\Requests\Api\V2;

use App\Auth\Role;
use App\Http\RequestContext;
use App\Services\Auth\ImpersonationService;

/**
 * Body of `startImpersonation` (`ImpersonationStartRequest`, feature 007 FR-044): `role`
 * (`mailboxUser` or `domainAdmin`, `notInList` otherwise) and `username` (1 to 255 characters);
 * unknown members are `unknownField`. A session that is already impersonating, a role that never
 * may impersonate and a domain administrator without `loginAs` are refused before validation
 * (research R16).
 */
class StartImpersonationRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $role = $this->input('role');
        app(ImpersonationService::class)->authorize(RequestContext::requirePrincipal($this), is_string($role) ? $role : null);

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'role' => ['bail', 'required', 'string', 'in:mailboxUser,domainAdmin'],
            'username' => ['bail', 'required', 'string', 'min:1', 'max:255'],
        ];
    }

    protected function memberViolations(array $body): array
    {
        $violations = [];

        foreach (array_keys($body) as $member) {
            if (! in_array($member, ['role', 'username'], true)) {
                $violations[(string) $member] = self::make('unknownField', [], 'This member is not accepted here.');
            }
        }

        return $violations;
    }

    public function role(): Role
    {
        return Role::from((string) $this->validated('role'));
    }

    public function username(): string
    {
        return (string) $this->validated('username');
    }
}
