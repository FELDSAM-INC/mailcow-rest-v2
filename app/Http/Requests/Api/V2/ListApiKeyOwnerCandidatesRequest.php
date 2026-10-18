<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Models\ApiKey;
use App\Support\Http\ListQuery;
use Illuminate\Support\Facades\Gate;

/**
 * Query of `listApiKeyOwnerCandidates` (FR-057): the `role` filter and the shared list parameters
 * (`sort` username, `q` over the username). Administrators only, as for listing keys.
 */
class ListApiKeyOwnerCandidatesRequest extends ApiFormRequest
{
    public const SORT_COLUMNS = ['username' => 'username'];

    private ?ListQuery $listQuery = null;

    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows('viewAny', ApiKey::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'role' => ['bail', 'sometimes', 'string', 'in:admin,domainAdmin'],
        ];
    }

    protected function passedValidation(): void
    {
        $this->listQuery = ListQuery::fromRequest($this, self::SORT_COLUMNS, 'username');
    }

    public function listQuery(): ListQuery
    {
        return $this->listQuery ??= ListQuery::fromRequest($this, self::SORT_COLUMNS, 'username');
    }

    public function role(): ?string
    {
        $role = $this->query('role');

        return is_string($role) ? $role : null;
    }
}
