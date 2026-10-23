<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\TemplateBulkActionRules;
use App\Models\Mailcow\MailboxTemplate;
use App\Services\Mailboxes\MailboxTemplateBulkActions;
use Illuminate\Support\Facades\Gate;

/**
 * Body of `applyMailboxTemplateBulkAction` (`MailboxTemplateBulkActionRequest`, gap G39): the
 * action `delete` and 1 to 100 targets (`tooMany` with `max: 100`); other members are
 * `immutable`. Administrators only; any other caller is refused as a whole.
 */
class MailboxTemplateBulkActionRequest extends ApiFormRequest
{
    use TemplateBulkActionRules;

    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows('delete', MailboxTemplate::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->templateBulkRules(MailboxTemplateBulkActions::ACTIONS, MailboxTemplateBulkActions::LIMIT);
    }
}
