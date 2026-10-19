<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\AccountValueRules;
use App\Policies\DomainAdminPolicy;
use App\Rules\AssignableDomain;
use Illuminate\Contracts\Validation\Validator;

/**
 * Body of `createDomainAdmin` (`DomainAdminCreateRequest`, feature 007 FR-014 to FR-018): username,
 * password and at least one existing primary domain, all required; optional status, forced flags
 * and permission flags; `unknownField` for anything else. Every violation is reported together.
 * Administrators only; the policy runs before validation.
 */
class StoreDomainAdminRequest extends ApiFormRequest
{
    use AccountValueRules;

    private const MEMBERS = ['username', 'password', 'domains', 'active', 'forcePasswordChange', 'forceSecondFactorSetup', 'permissions'];

    private ?AssignableDomain $domains = null;

    public function authorize(): bool
    {
        app(DomainAdminPolicy::class)->authorize(RequestContext::requirePrincipal($this));

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->accountRules(false) + $this->permissionRules() + [
            'domains' => ['bail', 'required', 'array', 'list'],
            'domains.*' => ['bail', $this->assignable()],
        ];
    }

    protected function memberViolations(array $body): array
    {
        return $this->unknownMembers($body, self::MEMBERS);
    }

    protected function additionalViolations(Validator $validator): array
    {
        return $this->passwordViolations($validator);
    }

    /**
     * @return array{username: string, password: string, domains: list<string>, active?: bool, forcePasswordChange?: bool, forceSecondFactorSetup?: bool, permissions?: array<string, bool>}
     */
    public function values(): array
    {
        /** @var array{username: string, password: string, active?: bool, forcePasswordChange?: bool, forceSecondFactorSetup?: bool, permissions?: array<string, bool>} $values */
        $values = $this->accountValues();

        return $values + ['domains' => $this->assignable()->normalised()];
    }

    private function assignable(): AssignableDomain
    {
        return $this->domains ??= new AssignableDomain;
    }
}
