<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Models\Mailcow\DomainTemplate;
use App\Services\DomainTemplates\DomainTemplateBulkActions;
use Illuminate\Support\Facades\Gate;

/**
 * Body of `applyDomainTemplateBulkAction` (`DomainTemplateBulkActionRequest`, gap G40): the
 * action `delete` and 1 to 100 targets (`tooMany` with `max: 100`); other members are
 * `immutable`. Administrators only; any other caller is refused as a whole.
 */
class DomainTemplateBulkActionRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows('delete', DomainTemplate::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'action' => ['bail', 'required', 'string', 'in:'.implode(',', DomainTemplateBulkActions::ACTIONS)],
            'targets' => ['bail', 'required', 'array', 'list', 'min:1', 'max:'.DomainTemplateBulkActions::LIMIT],
            'targets.*' => ['bail', 'array'],
            'targets.*.id' => ['bail', 'required', 'string', 'min:1'],
            'targets.*.version' => ['bail', 'sometimes', 'string'],
        ];
    }

    protected function memberViolations(array $body): array
    {
        $violations = [];

        foreach (array_diff(array_keys($body), ['action', 'targets']) as $member) {
            $violations[(string) $member] = self::make('immutable', [], 'This member cannot be set here.');
        }

        return $violations;
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
