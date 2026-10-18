<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Models\Mailcow\Domain;
use App\Services\Domains\DomainCapabilitiesService;
use Illuminate\Support\Facades\Gate;

/**
 * Body of `applyDomainBulkAction` (`DomainBulkActionRequest`): an action and 1 to 100 targets
 * (`tooMany` with `max: 100`). Administrators only; any other caller is refused as a whole.
 */
class DomainBulkActionRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows('bulk', Domain::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'action' => ['bail', 'required', 'string', 'in:activate,deactivate,delete'],
            'targets' => ['bail', 'required', 'array', 'list', 'min:1', 'max:'.DomainCapabilitiesService::BULK_LIMIT],
            'targets.*' => ['bail', 'array'],
            'targets.*.id' => ['bail', 'required', 'string', 'min:1'],
            'targets.*.version' => ['bail', 'sometimes', 'string'],
        ];
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
