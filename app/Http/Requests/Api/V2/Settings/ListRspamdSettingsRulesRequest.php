<?php

namespace App\Http\Requests\Api\V2\Settings;

use App\Rules\QueryBoolean;
use App\Services\Rspamd\RspamdSettingsRuleService;
use App\Support\Http\ListQuery;

/**
 * Query of `listRspamdSettingsRules` (feature 013 FR-052): the shared list parameters (sort `id`
 * ascending by default) and the `active` filter.
 */
class ListRspamdSettingsRulesRequest extends SettingsRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['active' => ['bail', new QueryBoolean]];
    }

    public function validationData(): array
    {
        return $this->query();
    }

    public function listQuery(): ListQuery
    {
        return ListQuery::fromRequest($this, RspamdSettingsRuleService::SORTS, 'id');
    }

    public function active(): ?bool
    {
        $active = $this->query('active');

        return $active === null ? null : in_array($active, ['true', '1'], true);
    }
}
