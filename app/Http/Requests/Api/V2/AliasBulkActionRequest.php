<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Models\Mailcow\Alias;
use App\Services\Domains\DomainCapabilitiesService;
use Illuminate\Support\Facades\Gate;

/**
 * Bodies of the bulk actions of feature 004 (`AliasBulkActionRequest`, `AliasDomainBulkActionRequest`,
 * `ResourceBulkActionRequest`): an action of the collection and 1 to 100 targets (`tooMany` with
 * `max: 100`). Administrators and domain administrators; any other caller is refused as a whole.
 */
class AliasBulkActionRequest extends ApiFormRequest
{
    /** @var list<string> */
    protected array $actions = ['activate', 'deactivate', 'showInGroupware', 'hideInGroupware', 'delete', 'copyOverAliasDomains'];

    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows('bulk', Alias::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'action' => ['bail', 'required', 'string', 'in:'.implode(',', $this->actions)],
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
