<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Quarantine\QuarantineQuery;
use App\Support\Http\ListQuery;

/**
 * Query of `listQuarantineItems` (feature 006 FR-008 to FR-011): the shared list parameters
 * (`limit` 25 by default, at most 1000; `offset`; `sort`, newest first by default; `order`; `q`).
 */
class ListQuarantineItemsRequest extends ApiFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    public function listQuery(): ListQuery
    {
        return ListQuery::fromRequest($this, QuarantineQuery::SORTS, 'arrivedAt', 'desc');
    }
}
