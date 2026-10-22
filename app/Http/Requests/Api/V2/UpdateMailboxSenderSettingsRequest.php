<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Models\Mailcow\Alias;
use App\Services\Mailboxes\MailboxScope;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Body of `updateMailboxSenderSettings` (`MailboxSenderSettingsPatch`, JSON Merge Patch): at least
 * one member, and lists of strings. The entry rules are SenderSettingsUpdater's. The role is checked
 * first (`403`), then the mailbox in the caller's scope (`404`; resources and other addresses too),
 * then the body (`422`).
 */
class UpdateMailboxSenderSettingsRequest extends ApiFormRequest
{
    protected bool $requiresNonEmptyBody = true;

    private ?string $mailbox = null;

    public function authorize(): bool
    {
        $principal = RequestContext::requirePrincipal($this);

        if (! Gate::forUser($principal)->allows('update', Alias::class)) {
            return false;
        }

        $this->mailbox = app(MailboxScope::class)->resolve($principal, (string) $this->route('mailbox'))
            ?? throw new NotFoundHttpException('The mailbox does not exist.');

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'explicitPermissions' => ['bail', 'sometimes', 'array', 'list'],
            'explicitPermissions.*' => ['bail', 'string', 'min:1', 'max:255'],
            'externalSenderAddresses' => ['bail', 'sometimes', 'array', 'list'],
            'externalSenderAddresses.*' => ['bail', 'string', 'min:1', 'max:255'],
        ];
    }

    public function mailbox(): string
    {
        return (string) $this->mailbox;
    }

    /**
     * @return array<string, mixed>
     */
    public function members(): array
    {
        return $this->jsonBody();
    }
}
