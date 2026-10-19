<?php

namespace App\Services\DomainAdmins;

use App\Auth\Principal;
use App\Services\Accounts\AccountDeleter;
use Illuminate\Support\Facades\DB;

/**
 * Deletes domain administrator accounts left without any domain assignment, as legacy domain
 * deletion does (FR-038, research D18): `admin` rows with `superadmin = 0` and no `domain_admins`
 * row, and `da_acl` rows of usernames without assignment. Every such account goes, including ones
 * orphaned earlier. Feature 007 (FR-032): each account gets the full deletion cascade of
 * App\Services\Accounts\AccountDeleter (second factors, passkeys, tokens, API keys revoked as
 * `ownerDeleted`, preferences, sessions), audited as its own `domainAdminDeletion` with
 * `trigger: domainDeletion` in the domain deletion's transaction.
 */
class OrphanedDomainAdminRemover
{
    public function __construct(private readonly AccountDeleter $deleter) {}

    /**
     * Accounts that are orphaned now.
     *
     * @return list<string>
     */
    public function candidates(): array
    {
        return $this->query(null);
    }

    /**
     * Accounts that would be orphaned once the domain's assignments are gone (the preview).
     *
     * @return list<string>
     */
    public function candidatesWithout(string $domain): array
    {
        return $this->query($domain);
    }

    /**
     * Deletes the orphaned accounts with the full cascade, and the permission rows of usernames
     * without assignment; returns the deleted usernames, sorted. Runs inside the domain deletion's
     * transaction; $domain is the deleted domain.
     *
     * @return list<string>
     */
    public function remove(Principal $actor, ?string $domain = null): array
    {
        $usernames = $this->candidates();

        $this->deleter->deleteOrphanedDomainAdmins($usernames, $actor, $domain);
        DB::table('da_acl')->whereNotIn('username', DB::table('domain_admins')->select('username'))->delete();

        return $usernames;
    }

    /**
     * @return list<string>
     */
    private function query(?string $withoutDomain): array
    {
        return DB::table('admin')
            ->where('superadmin', 0)
            ->whereNotExists(function ($q) use ($withoutDomain): void {
                $q->selectRaw('1')->from('domain_admins')->whereColumn('domain_admins.username', 'admin.username');

                if ($withoutDomain !== null) {
                    $q->where('domain_admins.domain', '<>', $withoutDomain);
                }
            })
            ->orderBy('username')
            ->pluck('username')
            ->map(fn (mixed $u): string => (string) $u)
            ->all();
    }
}
