<?php

namespace App\Services\Accounts;

use App\Audit\AuditEventType;
use App\Auth\AccountKind;
use App\Auth\Principal;
use App\Auth\Role;
use App\Services\ApiKeys\ApiKeyService;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SessionRegistry;
use App\Services\Preferences\PreferencesService;
use Illuminate\Support\Facades\DB;

/**
 * The one deletion cascade of administrators and domain administrators (feature 007 FR-030 to
 * FR-034, research R10), also used by feature 002's domain deletion for domain administrators left
 * without a domain (FR-032). Inside the caller's transaction it removes the rows legacy removes
 * (`domain_admins`, `admin`, `da_acl`, `tfa`, `fido2`) and the unused single sign-on tokens
 * (`da_sso`), revokes the account's API keys permanently (`ownerDeleted`, revoked by the deleting
 * administrator), removes its preferences and writes the audit record. After the commit the
 * account's sessions end (feature 005's SessionRegistry) and its generation is bumped, which ends
 * every impersonation of it. Audit records are kept.
 */
class AccountDeleter
{
    public function __construct(
        private readonly ApiKeyService $keys,
        private readonly PreferencesService $preferences,
        private readonly SessionRegistry $registry,
        private readonly AccountGeneration $generations,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  string  $username  the stored username
     * @param  string  $trigger  `request`, or `domainDeletion` for feature 002's orphan cleanup
     * @param  list<string>  $removedDomains  assignments already removed by the triggering change
     */
    public function delete(Role $role, string $username, Principal $actor, ?string $bulkRequestId = null, string $trigger = 'request', array $removedDomains = []): void
    {
        $domains = DB::table('domain_admins')->where('username', $username)->pluck('domain')->map(fn (mixed $d): string => (string) $d)->all();
        $secondFactors = DB::table('tfa')->where('username', $username)->count();
        $passkeys = DB::table('fido2')->where('username', $username)->count();
        $tokens = DB::table('da_sso')->where('username', $username)->count();

        foreach (['domain_admins', 'admin', 'da_acl', 'tfa', 'fido2', 'da_sso'] as $table) {
            DB::table($table)->where('username', $username)->delete();
        }

        $revoked = $this->keys->revokeAllForOwner($username, $actor->actingAccount()->username, 'ownerDeleted');
        $this->preferences->deleteForAccount(AccountKind::Admin, $username);

        $details = [
            'revokedApiKeyIds' => $revoked,
            'removedSecondFactors' => $secondFactors,
            'removedPasskeys' => $passkeys,
        ];

        if ($role === Role::DomainAdmin) {
            $all = array_values(array_unique([...$domains, ...$removedDomains]));
            sort($all);
            $details += ['domains' => $all, 'removedSsoTokens' => $tokens, 'trigger' => $trigger];
        }

        if ($bulkRequestId !== null) {
            $details['bulkRequestId'] = $bulkRequestId;
        }

        $this->audit->record(
            $role === Role::Admin ? AuditEventType::AdminDeletion : AuditEventType::DomainAdminDeletion,
            true,
            $actor,
            ['type' => $role === Role::Admin ? 'admin' : 'domainAdmin', 'id' => $username, 'name' => $username],
            null,
            null,
            $details,
        );

        DB::afterCommit(function () use ($username): void {
            $this->registry->endAll(AccountKind::Admin, mb_strtolower($username));
            $this->generations->bump('admin', $username);
        });
    }

    /**
     * Feature 002's domain deletion: deletes the domain administrators that the deletion of
     * $domain left without any assignment, each with the full cascade, inside its transaction.
     *
     * @param  list<string>  $usernames
     */
    public function deleteOrphanedDomainAdmins(array $usernames, Principal $actor, ?string $domain = null): void
    {
        foreach ($usernames as $username) {
            $this->delete(Role::DomainAdmin, $username, $actor, null, 'domainDeletion', $domain === null ? [] : [$domain]);
        }
    }
}
