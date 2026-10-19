<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\AccountValueRules;
use App\Policies\AdminPolicy;
use Illuminate\Contracts\Validation\Validator;

/**
 * Body of `createAdmin` (`AdminCreateRequest`, feature 007 FR-014 to FR-017): username and password
 * required, status and forced flags optional; `unknownField` for anything else. Only a signed-in
 * administrator; the policy runs before validation.
 */
class StoreAdminRequest extends ApiFormRequest
{
    use AccountValueRules;

    private const MEMBERS = ['username', 'password', 'active', 'forcePasswordChange', 'forceSecondFactorSetup'];

    public function authorize(): bool
    {
        app(AdminPolicy::class)->authorize(RequestContext::requirePrincipal($this), AdminPolicy::WRITE);

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->accountRules(false);
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
     * @return array{username: string, password: string, active?: bool, forcePasswordChange?: bool, forceSecondFactorSetup?: bool}
     */
    public function values(): array
    {
        /** @var array{username: string, password: string, active?: bool, forcePasswordChange?: bool, forceSecondFactorSetup?: bool} */
        return $this->accountValues();
    }
}
