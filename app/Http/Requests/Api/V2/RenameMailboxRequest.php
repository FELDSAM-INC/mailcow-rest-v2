<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Models\Mailcow\Mailbox;
use App\Rules\StrictBoolean;
use App\Services\Mailboxes\MailboxScope;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Body of `renameMailbox` (`MailboxRenameRequest`, feature 003 FR-043): the new local part and the
 * explicit choice whether to keep the old address as an alias; unknown members are `immutable`. The
 * role is checked first (`403`), then the scope (`404`), then the body (`422`).
 */
class RenameMailboxRequest extends ApiFormRequest
{
    private ?string $address = null;

    public function authorize(): bool
    {
        $principal = RequestContext::requirePrincipal($this);

        if (! Gate::forUser($principal)->allows('rename', Mailbox::class)) {
            return false;
        }

        $this->address = app(MailboxScope::class)->resolve($principal, (string) $this->route('mailbox'))
            ?? throw new NotFoundHttpException('The mailbox does not exist.');

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'newLocalPart' => ['bail', 'required', 'string', 'max:255'],
            'keepOldAddressAsAlias' => ['bail', 'required', new StrictBoolean],
        ];
    }

    protected function memberViolations(array $body): array
    {
        $violations = [];

        foreach (array_diff(array_keys($body), ['newLocalPart', 'keepOldAddressAsAlias']) as $member) {
            $violations[(string) $member] = self::make('immutable', [], 'This member cannot be set here.');
        }

        return $violations;
    }

    public function address(): string
    {
        return (string) $this->address;
    }
}
