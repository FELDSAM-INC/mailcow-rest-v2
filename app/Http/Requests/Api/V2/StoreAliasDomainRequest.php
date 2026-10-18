<?php

namespace App\Http\Requests\Api\V2;

use App\Auth\PermissionFlag;
use App\Auth\Role;
use App\Exceptions\PermissionFlagRequiredException;
use App\Http\RequestContext;
use App\Models\Mailcow\Alias;
use App\Rules\DkimSelector;
use App\Services\Domains\DomainRules;
use App\Services\Identity\IdentityService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Body of `createAliasDomain` (`AliasDomainCreateRequest`): the contract's types, bounds and
 * enumerations; the name, target and flag rules are the services'. The role is checked first
 * (`403 forbidden`), then, for a domain administrator, the `aliasDomains` permission
 * (`403 permission-flag-required`), both before the body (feature 004 FR-006).
 */
class StoreAliasDomainRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $principal = RequestContext::requirePrincipal($this);

        if (! Gate::forUser($principal)->allows('create', Alias::class)) {
            return false;
        }

        if ($principal->role === Role::DomainAdmin && ! (app(IdentityService::class)->permissions($principal)[PermissionFlag::AliasDomains->value] ?? false)) {
            throw new PermissionFlagRequiredException([PermissionFlag::AliasDomains], detail: 'Creating alias domains needs the aliasDomains permission.');
        }

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rateLimit = fn (): bool => is_array($this->input('rateLimit'));

        return [
            'name' => ['bail', 'required', 'string', 'min:1', 'max:253'],
            'targetDomain' => ['bail', 'required', 'string', 'min:1', 'max:253'],
            'active' => ['bail', 'sometimes', 'boolean'],
            'rateLimit' => ['bail', 'nullable', 'array'],
            'rateLimit.value' => ['bail', Rule::requiredIf($rateLimit), 'integer', 'between:1,'.DomainRules::MAX_INT],
            'rateLimit.frame' => ['bail', Rule::requiredIf($rateLimit), 'string', 'in:second,minute,hour,day'],
            'dkimSelector' => ['bail', 'sometimes', 'string', 'max:255', new DkimSelector],
            'dkimKeySize' => ['bail', 'sometimes', 'integer', 'in:1024,2048,3072,4096'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function members(): array
    {
        $body = json_decode((string) $this->getContent(), true);

        return is_array($body) ? $body : [];
    }
}
