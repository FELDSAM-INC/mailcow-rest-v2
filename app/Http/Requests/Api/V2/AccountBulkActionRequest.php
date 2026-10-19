<?php

namespace App\Http\Requests\Api\V2;

use App\Auth\Role;
use App\Http\RequestContext;
use App\Policies\AdminPolicy;
use App\Policies\DomainAdminPolicy;
use App\Services\Accounts\AccountBulkAction;

/**
 * Body of `applyAdminBulkAction` and `applyDomainAdminBulkAction` (`AccountBulkActionRequest`,
 * feature 007 FR-073, FR-075): one of the four actions (`notInList` otherwise) and 1 to 100 targets
 * (`tooMany` with `max: 100`); unknown members are `unknownField`. The role (and for administrator
 * accounts the session) is checked first, refusing the whole request.
 */
class AccountBulkActionRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $principal = RequestContext::requirePrincipal($this);

        if ($this->role() === Role::Admin) {
            app(AdminPolicy::class)->authorize($principal, AdminPolicy::WRITE);
        } else {
            app(DomainAdminPolicy::class)->authorize($principal);
        }

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'action' => ['bail', 'required', 'string', 'in:'.implode(',', array_map(fn (AccountBulkAction $a): string => $a->value, AccountBulkAction::cases()))],
            'targets' => ['bail', 'required', 'array', 'list', 'min:1', 'max:'.(int) config('restv2.account_bulk_limit', 100)],
            'targets.*' => ['bail', 'array'],
            'targets.*.id' => ['bail', 'required', 'string', 'min:1', 'max:255'],
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

    /**
     * The role of the collection: `admins` or `domain-admins`.
     */
    public function role(): Role
    {
        return str_starts_with((string) $this->route()?->getName(), 'admin-') ? Role::Admin : Role::DomainAdmin;
    }

    public function action(): AccountBulkAction
    {
        return AccountBulkAction::from((string) $this->validated('action'));
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
