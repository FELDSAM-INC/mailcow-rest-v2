<?php

namespace App\Http\Requests\Api\V2;

use App\Auth\PermissionFlag;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\ResolvesSelfServiceMailbox;
use App\Rules\ExcludesMember;
use App\Services\SelfService\PermissionGate;

/**
 * Body of `updateTemporaryAlias` (`TemporaryAliasPatch`, feature 005 FR-045): exactly one of a new
 * lifetime or `permanent: true`.
 */
class UpdateTemporaryAliasRequest extends ApiFormRequest
{
    use ResolvesSelfServiceMailbox;

    protected bool $requiresNonEmptyBody = true;

    protected function afterMailboxResolved(): void
    {
        app(PermissionGate::class)->requireChange(RequestContext::requirePrincipal($this), PermissionFlag::SpamAlias);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'lifetimeHours' => ['bail', 'sometimes', 'integer', 'between:1,87600', new ExcludesMember('permanent')],
            'permanent' => ['bail', 'sometimes', 'accepted', 'boolean'],
        ];
    }

    /**
     * @return array{lifetimeHours?: int, permanent?: bool}
     */
    public function patch(): array
    {
        return $this->has('lifetimeHours') ? ['lifetimeHours' => (int) $this->input('lifetimeHours')] : ['permanent' => true];
    }
}
