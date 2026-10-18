<?php

namespace App\Http\Requests\Api\V2;

use App\Rules\QueryBoolean;
use App\Support\Http\ListQuery;

/**
 * Query of the active and permanent ban lists (feature 011 FR-052): the shared list parameters
 * (`sort` `endsAt` or `network` for active bans, `startedAt` or `network` for permanent ones) and,
 * for active bans, `queuedForLifting`.
 */
class ListFail2BanBansRequest extends ApiFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['queuedForLifting' => ['bail', new QueryBoolean]];
    }

    public function listQuery(bool $permanent): ListQuery
    {
        return $permanent
            ? ListQuery::fromRequest($this, ['startedAt' => 'startedAt', 'network' => 'network'], 'startedAt', 'desc')
            : ListQuery::fromRequest($this, ['endsAt' => 'endsAt', 'network' => 'network'], 'endsAt', 'asc');
    }

    public function queuedForLifting(): ?bool
    {
        return match ($this->query('queuedForLifting')) {
            'true' => true,
            'false' => false,
            default => null,
        };
    }
}
