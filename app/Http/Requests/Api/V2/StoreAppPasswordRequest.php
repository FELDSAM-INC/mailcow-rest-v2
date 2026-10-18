<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\ResolvesSelfServiceMailbox;
use App\Rules\StrictBoolean;
use App\Services\AppPasswords\AppPasswordService;

/**
 * Body of `createAppPassword` (`AppPasswordCreateRequest`, feature 005 FR-038): the mailbox in scope
 * (`404`), then the `appPasswds` flag (`403`), then the body (`422`). The name's stored form and the
 * password rules are AppPasswordService's.
 */
class StoreAppPasswordRequest extends ApiFormRequest
{
    use ResolvesSelfServiceMailbox;

    protected function afterMailboxResolved(): void
    {
        app(AppPasswordService::class)->assertMayChange(RequestContext::requirePrincipal($this));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'max:255'],
            'password' => ['bail', 'sometimes', 'string', 'min:1', 'max:4096'],
            'protocols' => ['bail', 'sometimes', 'array', 'list'],
            'protocols.*' => ['bail', 'string', 'distinct', 'in:'.implode(',', array_keys(AppPasswordService::PROTOCOLS))],
            'active' => ['bail', 'sometimes', new StrictBoolean],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function members(): array
    {
        return $this->only(['name', 'password', 'protocols', 'active']);
    }
}
