<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Policies\SyncJobPolicy;
use App\Services\SyncJobs\SyncJobBulkAction;
use App\Services\SyncJobs\SyncJobCapabilities;

/**
 * Body of `applySyncJobBulkAction` (`SyncJobBulkActionRequest`, feature 008 FR-023, research R15):
 * one of the legacy mass actions and 1 to the bulk limit (default 100) of targets, counted as sent
 * (`tooMany` with `max`), each a job identifier written as a decimal string. A malformed target
 * refuses the whole request, with every bad target named. The sync-jobs permission is checked first.
 */
class SyncJobBulkActionRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        app(SyncJobPolicy::class)->authorize(RequestContext::requirePrincipal($this));

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'action' => ['bail', 'required', 'string', 'in:'.implode(',', array_column(SyncJobBulkAction::cases(), 'value'))],
            'targets' => ['bail', 'required', 'array', 'list', 'min:1', 'max:'.SyncJobCapabilities::bulkLimit()],
            'targets.*' => ['bail', 'array'],
            'targets.*.id' => ['bail', 'required', 'string', 'regex:/^[1-9][0-9]{0,9}$/'],
            'targets.*.version' => ['bail', 'sometimes', 'string'],
        ];
    }

    public function action(): SyncJobBulkAction
    {
        return SyncJobBulkAction::from((string) $this->validated('action'));
    }

    /**
     * @return list<array{id: string, version?: string|null}>
     */
    public function targets(): array
    {
        return array_values(array_map(
            fn (array $t): array => ['id' => (string) $t['id'], 'version' => isset($t['version']) ? (string) $t['version'] : null],
            (array) $this->validated('targets'),
        ));
    }
}
