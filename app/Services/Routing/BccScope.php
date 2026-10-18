<?php

namespace App\Services\Routing;

use App\Auth\Principal;
use App\Auth\Role;
use App\Services\Identity\IdentityService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The BCC maps a caller reaches (feature 009 FR-001, FR-003, FR-056, research R18): every map for
 * an administrator, orphaned ones included; for a domain administrator the maps whose `domain` is
 * a domain of their scope (001's IdentityService: active assignment, existing domain) or an alias
 * domain of such a domain, evaluated from mailcow's data on every call. Mailbox users reach none.
 */
class BccScope
{
    public function __construct(private readonly IdentityService $identity) {}

    /**
     * Restricts a query whose `$column` holds a map's domain (or a candidate domain).
     *
     * @template TQuery of Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function restrict(Builder $query, Principal $principal, string $column = 'bcc_maps.domain'): Builder
    {
        if ($principal->role === Role::Admin) {
            return $query;
        }

        if ($principal->role !== Role::DomainAdmin) {
            return $query->whereRaw('1 = 0');
        }

        $domains = $this->identity->scope($principal)->domains;

        if ($domains === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $inner) use ($column, $domains): void {
            $inner->whereIn($column, $domains)
                ->orWhereIn($column, fn (Builder $sub) => $sub->select('alias_domain')->from('alias_domain')->whereIn('target_domain', $domains));
        });
    }

    /**
     * The ASCII domains and alias domains a domain administrator's maps may belong to, sorted;
     * every existing domain and alias domain for an administrator.
     *
     * @return list<string>
     */
    public function domains(Principal $principal): array
    {
        if ($principal->role === Role::Admin) {
            $all = DB::table('domain')->pluck('domain')->merge(DB::table('alias_domain')->pluck('alias_domain'));
        } elseif ($principal->role === Role::DomainAdmin) {
            $scope = $this->identity->scope($principal)->domains;
            $all = collect($scope)->merge($scope === [] ? [] : DB::table('alias_domain')->whereIn('target_domain', $scope)->pluck('alias_domain'));
        } else {
            return [];
        }

        $domains = array_values(array_unique($all->map(fn (mixed $d): string => (string) $d)->all()));
        sort($domains);

        return $domains;
    }

    /** Whether a map of the domain (ASCII) is in the caller's scope. */
    public function contains(Principal $principal, string $domain): bool
    {
        if ($principal->role === Role::Admin) {
            return true;
        }

        return in_array($domain, $this->domains($principal), true);
    }
}
