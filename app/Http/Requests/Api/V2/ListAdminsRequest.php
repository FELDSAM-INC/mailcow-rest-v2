<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\AccountListRules;
use App\Policies\AdminPolicy;
use App\Services\Accounts\AccountQuery;

/**
 * Query of `listAdmins` (feature 007 FR-011): the account list parameters. Administrators only,
 * with a session or a key; the policy runs before validation.
 */
class ListAdminsRequest extends ApiFormRequest
{
    use AccountListRules;

    public function authorize(): bool
    {
        app(AdminPolicy::class)->authorize(RequestContext::requirePrincipal($this), AdminPolicy::VIEW);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->query();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->accountListRules();
    }

    protected function sorts(): array
    {
        return AccountQuery::ADMIN_SORTS;
    }

    /**
     * @return array{active: bool|null, secondFactorStatus: string|null, forcePasswordChange: bool|null, forceSecondFactorSetup: bool|null}
     */
    public function filters(): array
    {
        return $this->accountFilters();
    }
}
