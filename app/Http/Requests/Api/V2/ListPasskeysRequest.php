<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Passkeys\PasskeyService;
use App\Support\Http\ListQuery;

/**
 * Query of `listPasskeys`: the shared list parameters (`limit` 1–1000, default 25, `offset`,
 * `sort` registeredAt or name, `order`, `q` over the name and the authenticator description).
 */
class ListPasskeysRequest extends ApiFormRequest
{
    private ?ListQuery $listQuery = null;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    protected function passedValidation(): void
    {
        $this->listQuery = ListQuery::fromRequest($this, PasskeyService::SORT_COLUMNS, 'registeredAt');
    }

    public function listQuery(): ListQuery
    {
        return $this->listQuery ??= ListQuery::fromRequest($this, PasskeyService::SORT_COLUMNS, 'registeredAt');
    }
}
