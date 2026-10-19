<?php

namespace App\Services\Webmail;

use App\Audit\AuditEventType;
use App\Auth\PermissionFlag;
use App\Auth\Principal;
use App\Auth\Role;
use App\Exceptions\PermissionFlagRequiredException;
use App\Exceptions\ProblemException;
use App\Exceptions\ValidationProblemException;
use App\Http\Requests\Api\V2\ApiFormRequest;
use App\Services\Identity\IdentityService;
use App\Services\Mailboxes\MailboxScope;
use App\Support\Http\ProblemType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Webmail sign-in on a user's behalf (feature 007 FR-054 to FR-056, research R21), legacy
 * `sogo-auth.php?login=` through feature 005's webmail hand-off. The route refuses keys
 * (`session-required`), impersonating sessions (`not-allowed-while-impersonating`) and restricted
 * sessions first; then, in this order: mailbox users (`forbidden`), domain administrators without
 * `loginAs` (`permission-flag-required`), the server setting `ALLOW_ADMIN_EMAIL_LOGIN` off
 * (`webmail-on-behalf-disabled`), a server without the groupware (feature 005's
 * `groupware-disabled`), a mailbox that does not exist, is a resource or lies outside a domain
 * administrator's domains (`404`), and a mailbox that cannot use webmail (`422 notEligible` with
 * `reason`). The hand-off records the legacy `SSO` recent-login entry for the mailbox; the caller's
 * session in this API is not changed.
 */
class WebmailOnBehalfService
{
    public function __construct(
        private readonly WebmailHandOffService $handOffs,
        private readonly IdentityService $identity,
        private readonly MailboxScope $mailboxes,
    ) {}

    /**
     * @throws ProblemException|NotFoundHttpException|ValidationProblemException
     */
    public function signIn(Principal $caller, string $mailbox, Request $request): WebmailHandOff
    {
        if ($caller->role === Role::MailboxUser) {
            throw new ProblemException(ProblemType::Forbidden, 'Mailbox users open their own webmail through the webmail hand-off.');
        }

        if ($caller->role === Role::DomainAdmin && ! ($this->identity->permissions($caller)[PermissionFlag::LoginAs->value] ?? false)) {
            throw new PermissionFlagRequiredException([PermissionFlag::LoginAs], detail: 'Opening webmail on a user\'s behalf needs the loginAs permission.');
        }

        if (! config('restv2.allow_admin_email_login')) {
            throw new ProblemException(ProblemType::WebmailOnBehalfDisabled);
        }

        if (config('restv2.skip_sogo')) {
            throw new ProblemException(ProblemType::GroupwareDisabled);
        }

        $address = $this->mailboxes->resolve($caller, $mailbox) ?? throw new NotFoundHttpException('The mailbox does not exist.');
        $row = DB::table('mailbox')
            ->join('domain', 'domain.domain', '=', 'mailbox.domain')
            ->where('mailbox.username', $address)
            ->first(['mailbox.active', 'domain.active as domain_active']);

        if (! $row instanceof stdClass) {
            throw new NotFoundHttpException('The mailbox does not exist.');
        }

        $reason = match (true) {
            (int) $row->active === 2 => 'loginDisabled',
            (int) $row->active !== 1 => 'mailboxInactive',
            (int) $row->domain_active !== 1 => 'domainInactive',
            default => null,
        };

        if ($reason !== null) {
            throw new ValidationProblemException(['mailbox' => [ApiFormRequest::make('notEligible', ['reason' => $reason], 'The webmail of this mailbox cannot be opened.')]]);
        }

        return $this->handOffs->handOff($caller, $address, $request, AuditEventType::WebmailSignInOnBehalf, ['type' => 'mailbox', 'id' => $address, 'name' => null]);
    }
}
