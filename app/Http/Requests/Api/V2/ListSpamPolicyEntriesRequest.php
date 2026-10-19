<?php

namespace App\Http\Requests\Api\V2;

use App\Services\SpamPolicy\SpamPolicyEntryService;
use App\Support\Http\ListQuery;

/**
 * Query of the allow and block list reads (feature 006 FR-045): the shared list parameters (sort
 * `pattern` ascending by default, `createdAt`, `id`), `list` (`allow`, `block`) and `ownership`
 * (`own`, `inherited`).
 */
class ListSpamPolicyEntriesRequest extends ApiFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'list' => ['bail', 'string', 'in:allow,block'],
            'ownership' => ['bail', 'string', 'in:own,inherited'],
        ];
    }

    public function listQuery(): ListQuery
    {
        return ListQuery::fromRequest($this, SpamPolicyEntryService::SORTS, 'pattern', 'asc');
    }

    /**
     * @return array{list: string|null, ownership: string|null}
     */
    public function filters(): array
    {
        $list = $this->query('list');
        $ownership = $this->query('ownership');

        return ['list' => is_string($list) ? $list : null, 'ownership' => is_string($ownership) ? $ownership : null];
    }
}
