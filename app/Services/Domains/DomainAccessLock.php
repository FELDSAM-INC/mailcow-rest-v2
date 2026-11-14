<?php

namespace App\Services\Domains;

use App\Auth\Principal;
use App\Auth\Role;
use Illuminate\Support\Facades\DB;

/**
 * Recheck ownership inside a write transaction after locking the object being changed. Locking
 * reads see current assignments even under REPEATABLE READ and hold the authorizing rows until
 * commit, including an alias-domain mapping when it supplies access. Earlier path/bulk resolution
 * is not permission to mutate a subsequently transferred object.
 */
class DomainAccessLock
{
    public function primary(Principal $principal, string $domain, bool $requireExistingDomain = false): bool
    {
        if ($principal->role === Role::Admin) {
            return true;
        }

        if ($principal->role !== Role::DomainAdmin) {
            return false;
        }

        if ($requireExistingDomain && DB::table('domain')->where('domain', $domain)->sharedLock()->first(['domain']) === null) {
            return false;
        }

        return DB::table('domain_admins')->where('username', $principal->username)
            ->where('domain', $domain)->where('active', 1)->sharedLock()->first(['domain']) !== null;
    }

    public function includingAliases(Principal $principal, string $domain, bool $requireExistingDomain = false): bool
    {
        if ($this->primary($principal, $domain, $requireExistingDomain)) {
            return true;
        }

        if ($principal->role !== Role::DomainAdmin) {
            return false;
        }

        $alias = DB::table('alias_domain')->where('alias_domain', $domain)->sharedLock()->first(['target_domain']);

        return $alias !== null && $this->primary($principal, (string) $alias->target_domain, $requireExistingDomain);
    }
}
