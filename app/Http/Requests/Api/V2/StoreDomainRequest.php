<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\DomainValueRules;
use App\Models\Mailcow\Domain;
use Illuminate\Support\Facades\Gate;

/**
 * Body of `createDomain` (`DomainCreateRequest`): the types, bounds, enumerations and patterns of
 * the contract. The business rules (name syntax, whole MiB, quota order, tags, template) are
 * DomainRules', so that all of them are reported together. Administrators only; the policy runs
 * before validation.
 */
class StoreDomainRequest extends ApiFormRequest
{
    use DomainValueRules;

    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows('create', Domain::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'max:255'],
            'templateId' => ['bail', 'sometimes', 'integer', 'min:1'],
            'description' => ['bail', 'sometimes', 'string', 'max:255'],
            'restartGroupware' => ['bail', 'sometimes', 'boolean'],
            ...$this->valueRules(),
        ];
    }
}
