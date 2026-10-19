<?php

namespace App\Http\Requests\Api\V2\Settings;

use App\Services\MassMail\MassMailJobService;
use App\Support\Http\ListQuery;

/**
 * Query of `listMassMailJobRecipients` (feature 013 FR-077): the shared list parameters (address
 * ascending by default) and the `outcome` filter.
 */
class ListMassMailJobRecipientsRequest extends SettingsRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['outcome' => ['bail', 'string', 'in:pending,delivered,failed,skipped']];
    }

    public function validationData(): array
    {
        return $this->query();
    }

    public function listQuery(): ListQuery
    {
        return ListQuery::fromRequest($this, MassMailJobService::RECIPIENT_SORTS, 'address');
    }

    public function outcome(): ?string
    {
        $outcome = $this->query('outcome');

        return is_string($outcome) ? $outcome : null;
    }
}
