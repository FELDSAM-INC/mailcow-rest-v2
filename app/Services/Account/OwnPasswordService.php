<?php

namespace App\Services\Account;

use App\Audit\AuditEventType;
use App\Auth\AccountKind;
use App\Auth\PendingAction;
use App\Auth\Principal;
use App\Exceptions\ProblemException;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\PendingActionEvaluator;
use App\Services\Auth\SessionManager;
use App\Services\Auth\SessionRegistry;
use App\Services\Groupware\SogoDirectory;
use App\Services\Passwords\MailboxPasswordHasher;
use App\Support\Http\FieldViolations;
use App\Support\Http\ProblemType;
use App\Support\Passwords\NewPasswordValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Changing the signed-in account's own password (feature 005 FR-008–FR-015, research R4), a port
 * of legacy edit_user_account() (mailbox users), domain_admin('edit') (self) and admin('edit')
 * (oneself) with the spec's deviations:
 *
 *  1. a mailbox of an external identity provider is refused (`password-managed-externally`);
 *  2. the current password is required for every role (administrators too), except while a forced
 *     password change is pending, which the change resolves;
 *  3. the new password has no hash-scheme prefix and meets the policy (checked after the current
 *     password, so the rules never help someone who does not know it);
 *  4. the hash in `MAILCOW_PASS_SCHEME` is stored with legacy's statements, in one transaction with
 *     the audit record; for a mailbox the groupware directory is rebuilt afterwards (legacy's full
 *     update_sogo_static_view() with the memcached flush), unless the groupware is disabled;
 *  5. the account's other sessions of this API end unless the client keeps them, and the current
 *     session is renewed (new identifier, same handle).
 *
 * App passwords, API keys and legacy UI sessions are not touched.
 */
class OwnPasswordService
{
    /** Seconds Dovecot may accept the old password from its authentication cache (FR-041 there). */
    public const MAIL_SERVER_CACHE_SECONDS = 300;

    public function __construct(
        private readonly AccountCapabilities $accounts,
        private readonly CurrentPasswordCheck $currentPassword,
        private readonly NewPasswordValidator $rules,
        private readonly MailboxPasswordHasher $hasher,
        private readonly PendingActionEvaluator $pendingActions,
        private readonly SessionManager $sessions,
        private readonly SessionRegistry $registry,
        private readonly SogoDirectory $groupware,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Whether the change resolves a pending forced password change (no current password needed).
     */
    public function resolvesForcedChange(Principal $principal): bool
    {
        return in_array(PendingAction::PasswordChange, $this->pendingActions->evaluate($principal), true);
    }

    /**
     * @return array{sessionsEnded: int, activeAppPasswords: int, mailServerCacheSeconds: int}
     */
    public function change(Request $request, Principal $principal, ?string $current, string $new, bool $endOtherSessions): array
    {
        if (! $this->accounts->managedHere($principal)) {
            throw new ProblemException(ProblemType::PasswordManagedExternally);
        }

        $forced = $this->resolvesForcedChange($principal);

        if (! $forced) {
            $this->currentPassword->verify($principal, (string) $current, (string) $request->ip());
        }

        $violations = new FieldViolations;
        $this->rules->validate($new, $violations);
        $violations->throwIfAny();

        $hash = $this->hasher->hash($new)->value;
        $session = $request->session();
        $handle = $this->sessions->handle($session);

        $ended = DB::transaction(function () use ($principal, $hash, $endOtherSessions, $handle, $forced): int {
            if ($principal->accountKind === AccountKind::Mailbox) {
                DB::update(
                    "UPDATE `mailbox` SET `password` = ?, `attributes` = JSON_SET(`attributes`, '$.force_pw_update', '0'), `attributes` = JSON_SET(`attributes`, '$.passwd_update', NOW()) WHERE `username` = ? AND `authsource` = 'mailcow'",
                    [$hash, $principal->username],
                );
            } else {
                DB::update(
                    "UPDATE `admin` SET `password` = ?, `attributes` = JSON_SET(COALESCE(`attributes`, '{}'), '$.force_pw_update', '0') WHERE `username` = ?",
                    [$hash, $principal->username],
                );
            }

            $ended = $endOtherSessions ? $this->registry->endAll($principal->accountKind, $principal->username, $handle) : 0;

            $this->audit->record(
                AuditEventType::OwnPasswordChange,
                true,
                $principal,
                ['type' => 'accountPassword', 'id' => $principal->username, 'name' => null],
                $principal->authMethod->value,
                null,
                ['password' => 'changed', 'forcedChangeResolved' => $forced, 'sessionsEnded' => $ended],
            );

            return $ended;
        });

        if ($principal->accountKind === AccountKind::Mailbox) {
            $this->groupware->rebuild();
        }

        $this->sessions->renew($session);

        return [
            'sessionsEnded' => $ended,
            'activeAppPasswords' => $principal->accountKind === AccountKind::Mailbox
                ? DB::table('app_passwd')->where('mailbox', $principal->username)->where('active', 1)->count()
                : 0,
            'mailServerCacheSeconds' => self::MAIL_SERVER_CACHE_SECONDS,
        ];
    }
}
