<?php

namespace App\Http\Requests\Api\V2;

use App\Services\RateLimits\RateLimitService;
use App\Support\Http\ListQuery;

/**
 * Query of `listRateLimits` (feature 006 FR-051): the shared list parameters (sort `object`
 * ascending by default, or `kind`; `q` searches the object) and `kind` (`domain`, `aliasDomain`,
 * `mailbox`).
 */
class ListRateLimitsRequest extends ApiFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'kind' => ['bail', 'string', 'in:'.implode(',', RateLimitService::KINDS)],
        ];
    }

    public function listQuery(): ListQuery
    {
        return ListQuery::fromRequest($this, RateLimitService::SORTS, 'object', 'asc');
    }

    public function kind(): ?string
    {
        $kind = $this->query('kind');

        return is_string($kind) ? $kind : null;
    }
}
