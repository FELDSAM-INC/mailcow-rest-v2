<?php

namespace App\Http\Requests\Api\V2;

use App\Rules\QueryBoolean;
use App\Support\Http\ListQuery;

/**
 * Query of `listContainers` (feature 011 FR-009): `running` (`true`/`false`) and the shared list
 * parameters (`sort` of `ContainerSort`, default `name` ascending; `q` over name and image).
 * Authorisation ran before (the monitoring gate).
 */
class ListContainersRequest extends ApiFormRequest
{
    public const SORTS = ['name' => 'name', 'state' => 'state', 'startedAt' => 'startedAt'];

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['running' => ['bail', new QueryBoolean]];
    }

    public function listQuery(): ListQuery
    {
        return ListQuery::fromRequest($this, self::SORTS, 'name');
    }

    public function running(): ?bool
    {
        return match ($this->query('running')) {
            'true' => true,
            'false' => false,
            default => null,
        };
    }
}
