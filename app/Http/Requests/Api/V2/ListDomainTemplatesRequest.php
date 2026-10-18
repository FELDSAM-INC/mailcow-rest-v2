<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Models\Mailcow\DomainTemplate;
use App\Services\DomainTemplates\DomainTemplateService;
use App\Support\Http\ListQuery;
use Illuminate\Support\Facades\Gate;

/**
 * Query of `listDomainTemplates` (FR-049): the shared list parameters (`sort` name, id, createdAt
 * or modifiedAt, default name ascending; `q` on the name). Administrators only.
 */
class ListDomainTemplatesRequest extends ApiFormRequest
{
    private ?ListQuery $listQuery = null;

    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows('viewAny', DomainTemplate::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    protected function passedValidation(): void
    {
        $this->listQuery = ListQuery::fromRequest($this, DomainTemplateService::SORTS, 'name');
    }

    public function listQuery(): ListQuery
    {
        return $this->listQuery ??= ListQuery::fromRequest($this, DomainTemplateService::SORTS, 'name');
    }
}
