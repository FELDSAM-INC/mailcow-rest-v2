<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Policies\QuarantinePolicy;

/**
 * Body of `applyQuarantineBulkAction` (`QuarantineBulkActionRequest`, feature 006 FR-027): one
 * action for 1 to 100 targets (the API-wide limit, `tooMany` with `max: 100`). The whole request is
 * refused before validation when the caller's role or flags never allow the action (`quarantine`).
 * Target IDs are strings without a pattern, as in the other bulk actions: a value that is not an
 * item ID, an unknown ID and an item outside the scope are `notFound`.
 */
class QuarantineBulkActionRequest extends ApiFormRequest
{
    public const LIMIT = 100;

    public function authorize(): bool
    {
        app(QuarantinePolicy::class)->authorize(RequestContext::requirePrincipal($this), QuarantinePolicy::ACT);

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'action' => ['bail', 'required', 'string', 'in:release,releaseWithoutLearning,learnAsSpam,delete'],
            'targets' => ['bail', 'required', 'array', 'list', 'min:1', 'max:'.self::LIMIT],
            'targets.*' => ['bail', 'array'],
            'targets.*.id' => ['bail', 'required', 'string', 'min:1'],
            'targets.*.version' => ['bail', 'sometimes', 'string'],
        ];
    }

    protected function memberViolations(array $body): array
    {
        $violations = [];

        foreach (array_keys($body) as $member) {
            if (! in_array($member, ['action', 'targets'], true)) {
                $violations[(string) $member] = self::make('unknownField', [], 'This member is not accepted here.');
            }
        }

        return $violations;
    }

    public function action(): string
    {
        return (string) $this->validated('action');
    }

    /**
     * @return list<array{id: string, version: string|null}>
     */
    public function targets(): array
    {
        return array_values(array_map(
            fn (array $t): array => ['id' => (string) $t['id'], 'version' => isset($t['version']) ? (string) $t['version'] : null],
            (array) $this->validated('targets'),
        ));
    }
}
