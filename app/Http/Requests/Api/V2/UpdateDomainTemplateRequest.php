<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\DomainValueRules;
use App\Models\Mailcow\DomainTemplate;
use App\Services\DomainTemplates\DomainTemplateService;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Body of `updateDomainTemplate` (`DomainTemplatePatch`, JSON Merge Patch). The role is checked
 * first (`403`), then the template (`404`), then the body (`422`).
 */
class UpdateDomainTemplateRequest extends ApiFormRequest
{
    use DomainValueRules;

    protected bool $requiresNonEmptyBody = true;

    public function authorize(): bool
    {
        if (! Gate::forUser(RequestContext::requirePrincipal($this))->allows('update', DomainTemplate::class)) {
            return false;
        }

        app(DomainTemplateService::class)->find((int) $this->route('templateId'))
            ?? throw new NotFoundHttpException('The domain template does not exist.');

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['name' => ['bail', 'sometimes', 'string', 'min:1', 'max:255'], ...$this->valueRules()];
    }
}
