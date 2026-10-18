<?php

namespace App\Http\Requests\Api\V2;

use App\Auth\PermissionFlag;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\ResolvesSelfServiceMailbox;
use App\Rules\StrictBoolean;
use App\Services\SelfService\PermissionGate;

/**
 * Body of `createTemporaryAlias` (`TemporaryAliasCreateRequest`, feature 005 FR-043): the mailbox in
 * scope (`404`), the `spamAlias` flag (`403`), then the body (`422`); the domain's membership is the
 * service's rule (`notInList`).
 */
class StoreTemporaryAliasRequest extends ApiFormRequest
{
    use ResolvesSelfServiceMailbox;

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
            'domain' => ['bail', 'required', 'string', 'max:255'],
            'description' => ['bail', 'sometimes', 'string', 'max:255'],
            'lifetimeHours' => ['bail', 'sometimes', 'integer', 'between:1,87600'],
            'permanent' => ['bail', 'sometimes', new StrictBoolean],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function members(): array
    {
        return $this->only(['domain', 'description', 'lifetimeHours', 'permanent']);
    }
}
