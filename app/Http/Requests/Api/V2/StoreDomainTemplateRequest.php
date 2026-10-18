<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\DomainValueRules;
use App\Models\Mailcow\DomainTemplate;
use Illuminate\Support\Facades\Gate;

/**
 * Body of `createDomainTemplate` (`DomainTemplateCreateRequest`). Administrators only.
 */
class StoreDomainTemplateRequest extends ApiFormRequest
{
    use DomainValueRules;

    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows('create', DomainTemplate::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['name' => ['bail', 'required', 'string', 'min:1', 'max:255'], ...$this->valueRules()];
    }
}
