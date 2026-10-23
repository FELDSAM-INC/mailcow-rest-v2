<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\TemplateBulkActionRules;
use App\Models\Mailcow\DomainTemplate;
use App\Services\DomainTemplates\DomainTemplateBulkActions;
use Illuminate\Support\Facades\Gate;

/**
 * Body of `applyDomainTemplateBulkAction` (`DomainTemplateBulkActionRequest`, gap G40): the
 * action `delete` and 1 to 100 targets (`tooMany` with `max: 100`); other members are
 * `immutable`. Administrators only; any other caller is refused as a whole.
 */
class DomainTemplateBulkActionRequest extends ApiFormRequest
{
    use TemplateBulkActionRules;

    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows('delete', DomainTemplate::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->templateBulkRules(DomainTemplateBulkActions::ACTIONS, DomainTemplateBulkActions::LIMIT);
    }
}
