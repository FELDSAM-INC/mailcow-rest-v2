<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Models\Mailcow\Domain;
use App\Services\Domains\DomainTagSummaryQuery;
use App\Support\Http\ListQuery;
use Illuminate\Support\Facades\Gate;

/**
 * Query of `listDomainTags` (FR-066): the shared list parameters (`sort` name or domainCount,
 * default name ascending; `q` on the tag). Administrators and domain administrators.
 */
class ListDomainTagsRequest extends ApiFormRequest
{
    private ?ListQuery $listQuery = null;

    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows('viewAny', Domain::class);
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
        $this->listQuery = ListQuery::fromRequest($this, DomainTagSummaryQuery::SORTS, 'name');
    }

    public function listQuery(): ListQuery
    {
        return $this->listQuery ??= ListQuery::fromRequest($this, DomainTagSummaryQuery::SORTS, 'name');
    }
}
