<?php

namespace App\Services\Accounts;

use App\Audit\AuditEventType;
use App\Auth\Role;
use App\Rules\AccountUsername;
use App\Services\Audit\RefusalAuditor;
use App\Services\Mailboxes\MailboxAddress;
use App\Support\Http\ProblemType;
use Illuminate\Http\Request;

/**
 * The target resolvers of feature 007 for the shared refusal auditing (research R22): accounts
 * (`admin`, `domainAdmin`) by the path's username or the creation's username, lower-cased and cut
 * to 255 characters; single sign-on tokens by the account; impersonations by the requested target;
 * webmail sign-ins by the mailbox. A bulk request refused as a whole is recorded once with the event
 * of its action. A token requested for an inactive account maps to `accountInactive`.
 */
final class AccountAuditTargets
{
    public static function register(RefusalAuditor $auditor): void
    {
        $auditor->resolveTargetsWith('admin', fn (Request $request, array $config): array => self::account($request, $config, Role::Admin));
        $auditor->resolveTargetsWith('domainAdmin', fn (Request $request, array $config): array => self::account($request, $config, Role::DomainAdmin));
        $auditor->resolveTargetsWith('ssoToken', fn (Request $request): array => ['target' => ['type' => 'ssoToken', 'id' => null, 'name' => self::username($request->route('username'))]]);
        $auditor->resolveTargetsWith('impersonation', self::impersonation(...));
        $auditor->resolveTargetsWith('webmailOnBehalf', fn (Request $request): array => ['target' => ['type' => 'mailbox', 'id' => self::mailbox($request->route('mailbox')), 'name' => null]]);
        $auditor->mapProblemType(ProblemType::AccountInactive->uri(), 'accountInactive');
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{target?: array{type: string, id: string|null, name: string|null}|null, details?: array<string, mixed>, event?: AuditEventType}
     */
    private static function account(Request $request, array $config, Role $role): array
    {
        $type = $role === Role::Admin ? 'admin' : 'domainAdmin';

        if (($config['event'] ?? null) === 'fromBulkAction') {
            $action = AccountBulkAction::tryFrom((string) $request->input('action'));

            return RefusalAuditor::bulkRefusal($request, $action?->auditEvent($role) ?? ($role === Role::Admin ? AuditEventType::AdminUpdate : AuditEventType::DomainAdminUpdate));
        }

        $username = self::username($request->route('username') ?? $request->input('username'));

        return ['target' => ['type' => $type, 'id' => $username, 'name' => $username]];
    }

    /**
     * @return array{target: array{type: string, id: string|null, name: string|null}, details: array<string, mixed>}
     */
    private static function impersonation(Request $request): array
    {
        $role = $request->input('role');
        $username = $request->input('username');

        if (! is_string($username)) {
            return ['target' => ['type' => 'impersonation', 'id' => null, 'name' => null], 'details' => []];
        }

        $name = $role === 'mailboxUser' ? self::mailbox($username) : self::username($username);

        return [
            'target' => ['type' => 'impersonation', 'id' => null, 'name' => $name],
            'details' => in_array($role, ['mailboxUser', 'domainAdmin'], true) ? ['targetRole' => $role] : [],
        ];
    }

    private static function username(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $username = mb_substr(AccountUsername::normalize($value), 0, 255);

        return $username === '' ? null : $username;
    }

    private static function mailbox(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return MailboxAddress::normalize($value)->address ?? mb_substr(mb_strtolower(trim($value)), 0, 255);
    }
}
