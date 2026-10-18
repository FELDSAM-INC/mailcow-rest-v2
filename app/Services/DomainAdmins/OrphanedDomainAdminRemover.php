<?php

namespace App\Services\DomainAdmins;

use Illuminate\Support\Facades\DB;

/**
 * Deletes domain administrator accounts left without any domain assignment, as legacy domain
 * deletion does (FR-038, research D18): `admin` rows with `superadmin = 0` and no `domain_admins`
 * row, and `da_acl` rows of usernames without assignment. Every such account goes, including ones
 * orphaned earlier. Their API keys then fail 001's owner check (`api-key-invalid`). Feature 007
 * extends this class with the full cascade of its FR-032.
 */
class OrphanedDomainAdminRemover
{
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
     * Deletes the orphaned accounts and permission rows; returns the deleted usernames, sorted.
     *
     * @return list<string>
     */
    public function remove(): array
    {
        $usernames = $this->candidates();

        DB::table('admin')->where('superadmin', 0)->whereNotIn('username', DB::table('domain_admins')->select('username'))->delete();
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
