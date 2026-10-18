<?php

namespace App\Services\Domains;

use App\Auth\Principal;
use App\Auth\Role;
use App\Support\Domain\DomainName;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a domain name from a path or a bulk target for a caller (FR-001, FR-003, FR-006): the
 * normalised ASCII name when it is a `domain` row in the caller's scope, otherwise null, which the
 * caller answers with "not found" exactly like a name that does not exist. Alias domain names and
 * invalid names are never resolved. Evaluated from mailcow's data on every call (FR-002).
 */
class DomainScope
{
    public function resolve(Principal $principal, string $rawName): ?string
    {
        $name = DomainName::normalizeValid($rawName);

        if ($name === null) {
            return null;
        }

        $query = DB::table('domain')->where('domain', $name);

        if ($principal->role === Role::DomainAdmin) {
            $query->whereIn('domain', fn ($sub) => $sub->select('domain')->from('domain_admins')
                ->where('active', 1)
                ->where('username', $principal->username));
        } elseif ($principal->role !== Role::Admin) {
            return null;
        }

        return $query->exists() ? $name : null;
    }

    /**
     * Restricts a query on `domain` (alias `$column`) to the caller's scope.
     *
     * @template TQuery of \Illuminate\Database\Query\Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function restrict(Builder $query, Principal $principal, string $column = 'domain.domain'): Builder
    {
        if ($principal->role === Role::Admin) {
            return $query;
        }

        if ($principal->role === Role::DomainAdmin) {
            return $query->whereIn($column, fn ($sub) => $sub->select('domain')->from('domain_admins')
                ->where('active', 1)
                ->where('username', $principal->username));
        }

        return $query->whereRaw('1 = 0');
    }
}
