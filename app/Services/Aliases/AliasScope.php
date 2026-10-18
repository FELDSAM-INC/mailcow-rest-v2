<?php

namespace App\Services\Aliases;

use App\Auth\Principal;
use App\Auth\Role;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The aliases a caller may reach (feature 004 FR-001, research R19): administrators every alias,
 * orphaned ones included; domain administrators the aliases whose domain is one of their domains
 * (active assignments, active domain or not) or an alias domain of one of them; mailbox users none.
 * Evaluated from mailcow's data on every query (FR-002).
 */
class AliasScope
{
    /**
     * Restricts a query by its alias-domain column (an alias's `domain`, or any column naming a
     * domain or alias domain) to the caller's scope.
     *
     * @template TQuery of Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function restrict(Builder $query, Principal $principal, string $domainColumn = 'a.domain'): Builder
    {
        if ($principal->role === Role::Admin) {
            return $query;
        }

        if ($principal->role !== Role::DomainAdmin) {
            return $query->whereRaw('1 = 0');
        }

        $username = $principal->username;
        $assigned = fn ($sub) => $sub->select('domain')->from('domain_admins')->where('active', 1)->where('username', $username);

        return $query->where(fn (Builder $w) => $w->whereIn($domainColumn, $assigned)
            ->orWhereIn($domainColumn, fn ($sub) => $sub->select('alias_domain')->from('alias_domain')->whereIn('target_domain', $assigned)));
    }

    /** Whether a domain or alias domain (ASCII name) exists and is in the caller's scope (legacy hasDomainAccess). */
    public function reachesDomain(Principal $principal, string $domain): bool
    {
        return $this->restrict(DB::table('domain')->where('domain', $domain), $principal, 'domain.domain')->exists()
            || $this->restrict(DB::table('alias_domain')->where('alias_domain', $domain), $principal, 'alias_domain.alias_domain')->exists();
    }
}
