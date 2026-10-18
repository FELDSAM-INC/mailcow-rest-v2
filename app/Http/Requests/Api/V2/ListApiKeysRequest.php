<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Models\ApiKey;
use App\Services\ApiKeys\ApiKeyService;
use App\Support\Http\ListQuery;
use Illuminate\Support\Facades\Gate;

/**
 * Query of `listApiKeys` (FR-035, FR-058): the filters `ownerUsername`, `status` and
 * `accessLevel`, and the shared list parameters (`sort` createdAt, name, expiresAt or lastUsedAt,
 * newest first by default; `q` over the name, the prefix and the owner's username). Administrators
 * only; the policy runs before validation (R8).
 */
class ListApiKeysRequest extends ApiFormRequest
{
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
            'ownerUsername' => ['bail', 'sometimes', 'string', 'max:255'],
            'status' => ['bail', 'sometimes', 'string', 'in:active,expired,revoked'],
            'accessLevel' => ['bail', 'sometimes', 'string', 'in:readOnly,readWrite'],
        ];
    }

    protected function passedValidation(): void
    {
        $this->listQuery = ListQuery::fromRequest($this, ApiKeyService::SORT_COLUMNS, 'createdAt', 'desc');
    }

    public function listQuery(): ListQuery
    {
        return $this->listQuery ??= ListQuery::fromRequest($this, ApiKeyService::SORT_COLUMNS, 'createdAt', 'desc');
    }

    /**
     * @return array{ownerUsername: string|null, status: string|null, accessLevel: string|null}
     */
    public function filters(): array
    {
        $value = fn (string $name): ?string => is_string($this->query($name)) ? (string) $this->query($name) : null;

        return ['ownerUsername' => $value('ownerUsername'), 'status' => $value('status'), 'accessLevel' => $value('accessLevel')];
    }
}
