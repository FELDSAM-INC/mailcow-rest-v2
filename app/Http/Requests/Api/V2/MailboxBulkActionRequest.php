<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Models\Mailcow\Mailbox;
use App\Services\Mailboxes\MailboxBulkActions;
use App\Services\Mailboxes\MailboxCapabilities;
use App\Services\Mailboxes\MailboxValues;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Body of `applyMailboxBulkAction` (`MailboxBulkActionRequest`, feature 003 FR-054): an action, the
 * protocol of a protocol action (refused for the others, `conflictsWith`) and 1 to the bulk limit of
 * targets (`tooMany`). Administrators and domain administrators; any other caller is refused as a
 * whole.
 */
class MailboxBulkActionRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows('bulk', Mailbox::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'action' => ['bail', 'required', 'string', 'in:'.implode(',', MailboxBulkActions::ACTIONS)],
            'protocol' => ['bail', Rule::requiredIf(fn (): bool => $this->isProtocolAction()), 'string', 'in:'.implode(',', array_keys(MailboxValues::PROTOCOLS))],
            'targets' => ['bail', 'required', 'array', 'list', 'min:1', 'max:'.MailboxCapabilities::bulkLimit()],
            'targets.*' => ['bail', 'array'],
            'targets.*.id' => ['bail', 'required', 'string', 'min:1'],
            'targets.*.version' => ['bail', 'sometimes', 'string'],
        ];
    }

    protected function memberViolations(array $body): array
    {
        $violations = [];

        foreach (array_diff(array_keys($body), ['action', 'protocol', 'targets']) as $member) {
            $violations[(string) $member] = self::make('immutable', [], 'This member cannot be set here.');
        }

        $action = $body['action'] ?? null;

        if (array_key_exists('protocol', $body) && is_string($action) && ! in_array($action, MailboxBulkActions::PROTOCOL_ACTIONS, true)) {
            $violations['protocol'] = self::make('conflictsWith', ['field' => 'action', 'value' => $action], 'Only protocol actions take a protocol.');
        }

        return $violations;
    }

    public function isProtocolAction(): bool
    {
        return in_array($this->input('action'), MailboxBulkActions::PROTOCOL_ACTIONS, true);
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
