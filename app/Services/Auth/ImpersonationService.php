<?php

namespace App\Services\Auth;

use App\Auth\AccountKind;
use App\Auth\ImpersonationEndReason;
use App\Auth\PermissionFlag;
use App\Auth\Principal;
use App\Auth\Role;
use App\Exceptions\PermissionFlagRequiredException;
use App\Exceptions\ProblemException;
use App\Rules\AccountUsername;
use App\Services\Accounts\AccountGeneration;
use App\Services\Identity\IdentityService;
use App\Services\Mailboxes\MailboxScope;
use App\Support\Http\ProblemType;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Impersonation, legacy "login as" or "dual login" (feature 007 FR-042 to FR-053, research R16 to
 * R18), as a second session key on top of feature 001's session: the signed-in account stays the
 * session's principal, and requests act as the target with the impersonator attached.
 *
 *  - Eligibility (legacy `triggers.global.inc.php`): administrators impersonate any mailbox user and
 *    any domain administrator; domain administrators with `loginAs` the mailbox users of domains
 *    assigned to them through an active assignment; nobody an administrator. Inactive targets and
 *    mailboxes in inactive domains are allowed; resources and targets outside the scope answer 404.
 *  - Every request re-validates it (EnforceImpersonation): a target deleted, renamed or replaced (its
 *    creation time or generation differs), out of the impersonator's scope, or an impersonating
 *    domain administrator without `loginAs` ends it.
 *  - Nothing is written to legacy sessions (FR-053).
 */
class ImpersonationService
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly ImpersonationAudit $audit,
        private readonly AccountGeneration $generations,
        private readonly IdentityService $identity,
        private readonly MailboxScope $mailboxes,
    ) {}

    /**
     * Refuses a caller who may never impersonate: an impersonating session (`409`), a role that
     * never may (`403 forbidden`), a domain administrator without `loginAs` (`403
     * permission-flag-required`), checked before any target lookup.
     *
     * @throws ProblemException
     */
    public function authorize(Principal $principal, ?string $role): void
    {
        if ($principal->impersonator !== null) {
            throw new ProblemException(ProblemType::AlreadyImpersonating);
        }

        if ($principal->role === Role::MailboxUser) {
            throw new ProblemException(ProblemType::Forbidden, 'Mailbox users cannot impersonate other accounts.');
        }

        if ($principal->role === Role::DomainAdmin && $role === Role::DomainAdmin->value) {
            throw new ProblemException(ProblemType::Forbidden, 'Domain administrators impersonate only mailbox users.');
        }

        if ($principal->role === Role::DomainAdmin && ! ($this->identity->permissions($principal)[PermissionFlag::LoginAs->value] ?? false)) {
            throw new PermissionFlagRequiredException([PermissionFlag::LoginAs], detail: 'Impersonating mailbox users needs the loginAs permission.');
        }
    }

    /**
     * Starts the impersonation of an eligible target; returns the impersonating principal.
     *
     * @throws NotFoundHttpException
     */
    public function start(Session $session, Principal $impersonator, Role $role, string $username): Principal
    {
        $target = $role === Role::MailboxUser ? $this->mailbox($impersonator, $username) : $this->domainAdmin($username);
        $kind = $role === Role::MailboxUser ? AccountKind::Mailbox : AccountKind::Admin;
        $impersonation = [
            'id' => (string) Str::ulid(),
            'startedAt' => CarbonImmutable::now()->getTimestamp(),
            'target' => [
                'username' => (string) $target->username,
                'role' => $role->value,
                'accountKind' => $kind->value,
                'accountCreatedAt' => is_string($target->created ?? null) ? $target->created : null,
                'generation' => $this->generations->current($kind->value, (string) $target->username),
            ],
        ];

        $this->sessions->startImpersonation($session, $impersonation);
        $impersonating = $this->sessions->effectivePrincipal($session);
        assert($impersonating !== null);
        DB::transaction(fn () => $this->audit->started($impersonating));

        return $impersonating;
    }

    /**
     * Ends the session's impersonation, audited with how it ended.
     */
    public function end(Session $session, Principal $impersonating, string $endedBy, ?ImpersonationEndReason $reason = null): void
    {
        $this->audit->ended($impersonating, $endedBy, $reason);
        $this->sessions->endImpersonation($session);
    }

    /**
     * Why the impersonation is no longer valid, or null while it is (research R17). A deactivated
     * target does not end it (FR-052). $generation is the target's generation at the start.
     */
    public function validate(Principal $impersonating, int $generation): ?ImpersonationEndReason
    {
        $impersonator = $impersonating->impersonator;

        if ($impersonator === null) {
            return null;
        }

        $kind = $impersonating->accountKind;
        $row = $kind === AccountKind::Mailbox
            ? DB::table('mailbox')->where('username', $impersonating->username)->where('kind', '')->first(['username', 'domain', 'created'])
            : DB::table('admin')->where('username', $impersonating->username)->where('superadmin', 0)->first(['username', 'created']);

        if (! $row instanceof stdClass
            || ($impersonating->accountCreatedAt !== null && (string) $row->created !== $impersonating->accountCreatedAt)
            || $this->generations->current($kind->value, $impersonating->username) !== $generation) {
            return ImpersonationEndReason::TargetDeletedOrRenamed;
        }

        if ($impersonator->role === Role::DomainAdmin) {
            if ($kind !== AccountKind::Mailbox || ! $this->mailboxes->domainInScope($impersonator, (string) $row->domain)) {
                return ImpersonationEndReason::TargetOutOfScope;
            }

            if (! ($this->identity->permissions($impersonator)[PermissionFlag::LoginAs->value] ?? false)) {
                return ImpersonationEndReason::LoginAsRevoked;
            }
        }

        return null;
    }

    private function mailbox(Principal $impersonator, string $username): stdClass
    {
        $address = $this->mailboxes->resolve($impersonator, $username);
        $row = $address === null ? null : DB::table('mailbox')->where('username', $address)->where('kind', '')->first(['username', 'created']);

        return $row instanceof stdClass ? $row : throw new NotFoundHttpException('The mailbox does not exist.');
    }

    private function domainAdmin(string $username): stdClass
    {
        $row = DB::table('admin')->where('username', AccountUsername::normalize($username))->where('superadmin', 0)->first(['username', 'created']);

        return $row instanceof stdClass ? $row : throw new NotFoundHttpException('The domain administrator does not exist.');
    }
}
