<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\AccountListRules;
use App\Http\Requests\Api\V2\Concerns\ReadsRepeatedQuery;
use App\Policies\DomainAdminPolicy;
use App\Rules\QueryBoolean;
use App\Services\Accounts\AccountQuery;
use App\Services\Accounts\DomainAdminFlags;

/**
 * Query of `listDomainAdmins` (feature 007 FR-012): the account list parameters, the repeatable
 * `domain` (at most 100 names of 1 to 255 characters, ASCII or Unicode), and `permission` with
 * `permissionValue` (default `true`), which needs `permission`. Administrators only; the policy runs
 * before validation.
 */
class ListDomainAdminsRequest extends ApiFormRequest
{
    use AccountListRules;
    use ReadsRepeatedQuery;

    public function authorize(): bool
    {
        app(DomainAdminPolicy::class)->authorize(RequestContext::requirePrincipal($this));

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $data = $this->query();
        unset($data['domain']);
        $domains = $this->repeatedQuery('domain');

        return $domains === [] ? $data : $data + ['domain' => $domains];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->accountListRules() + [
            'domain' => ['bail', 'array', 'max:100'],
            'domain.*' => ['bail', 'string', 'min:1', 'max:255'],
            'permission' => ['bail', 'required_with:permissionValue', 'string', 'in:'.implode(',', array_keys(DomainAdminFlags::COLUMNS))],
            'permissionValue' => ['bail', new QueryBoolean],
        ];
    }

    protected function sorts(): array
    {
        return AccountQuery::DOMAIN_ADMIN_SORTS;
    }

    /**
     * @return array{active: bool|null, secondFactorStatus: string|null, forcePasswordChange: bool|null, forceSecondFactorSetup: bool|null, domains: list<string>, permission: string|null, permissionValue: bool}
     */
    public function filters(): array
    {
        $permission = $this->query('permission');

        return $this->accountFilters() + [
            'domains' => array_values(array_filter(array_map('trim', $this->repeatedQuery('domain')), fn (string $d): bool => $d !== '')),
            'permission' => is_string($permission) ? $permission : null,
            'permissionValue' => $this->query('permissionValue') !== 'false',
        ];
    }
}
