<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Rules\StrictInteger;
use App\Services\SyncJobs\SyncQueueSettings;

class UpdateSyncQueueSettingsRequest extends ApiFormRequest
{
    protected bool $requiresNonEmptyBody = true;

    public function authorize(): bool
    {
        app(SyncQueueSettings::class)->authorize(RequestContext::requirePrincipal($this));

        return true;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->jsonBody();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['maxParallel' => ['bail', 'sometimes', new StrictInteger, 'integer', 'between:1,32'],
            'maxBytesPerSecond' => ['bail', 'sometimes', new StrictInteger, 'integer', 'between:0,10737418240']];
    }

    /** @return array<string, int> */
    public function values(): array
    {
        return array_intersect_key($this->jsonBody(), SyncQueueSettings::COLUMNS);
    }
}
