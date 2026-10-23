<?php

namespace App\Http\Requests\Api\V2\Concerns;

/** Shared body shape for domain/mailbox template bulk actions; authorization stays local. */
trait TemplateBulkActionRules
{
    /**
     * @param  list<string>  $actions
     * @return array<string, list<mixed>>
     */
    protected function templateBulkRules(array $actions, int $limit): array
    {
        return [
            'action' => ['bail', 'required', 'string', 'in:'.implode(',', $actions)],
            'targets' => ['bail', 'required', 'array', 'list', 'min:1', 'max:'.$limit],
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
     * Keep target order, duplicate IDs and the supplied version for the bulk runner.
     *
     * @return list<array{id: string, version: string|null}>
     */
    public function targets(): array
    {
        return array_values(array_map(
            fn (array $target): array => ['id' => (string) $target['id'], 'version' => isset($target['version']) ? (string) $target['version'] : null],
            (array) $this->validated('targets'),
        ));
    }
}
