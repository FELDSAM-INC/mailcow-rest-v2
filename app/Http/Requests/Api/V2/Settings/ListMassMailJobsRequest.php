<?php

namespace App\Http\Requests\Api\V2\Settings;

use App\Services\MassMail\MassMailJobService;
use App\Support\Http\ListQuery;

/**
 * Query of `listMassMailJobs` (feature 013 FR-080): the shared list parameters (newest first by
 * default) and the `status` filter.
 */
class ListMassMailJobsRequest extends SettingsRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['status' => ['bail', 'string', 'in:running,completed,cancelled']];
    }

    public function validationData(): array
    {
        return $this->query();
    }

    public function listQuery(): ListQuery
    {
        return ListQuery::fromRequest($this, MassMailJobService::JOB_SORTS, 'createdAt', 'desc');
    }

    public function status(): ?string
    {
        $status = $this->query('status');

        return is_string($status) ? $status : null;
    }
}
