<?php

namespace App\Services\Accounts;

use App\Auth\Role;
use App\Support\Domain\DomainName;
use App\Support\Http\ListQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The administrator and domain administrator lists (feature 007 FR-010 to FR-012, research R14):
 * one paged query on `admin` with the filters, the search and the sort (ties broken by username,
 * ascending), then AccountRepresentation's batched queries for the page. Domain administrators
 * include accounts without any assignment.
 */
class AccountQuery
{
    /** Contract sort values of administrators. */
    public const ADMIN_SORTS = ['username' => 'username', 'active' => 'active', 'createdAt' => 'created', 'modifiedAt' => 'modified'];

    /** Contract sort values of domain administrators. */
    public const DOMAIN_ADMIN_SORTS = self::ADMIN_SORTS + ['domainCount' => 'domainCount'];

    public function __construct(private readonly AccountRepresentation $accounts) {}

    /**
     * @param  array{active?: bool|null, secondFactorStatus?: string|null, forcePasswordChange?: bool|null, forceSecondFactorSetup?: bool|null, domains?: list<string>, permission?: string|null, permissionValue?: bool}  $filters
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}
     */
    public function page(Role $role, ListQuery $list, array $filters): array
    {
        $query = DB::table('admin')->where('admin.superadmin', $role === Role::Admin ? 1 : 0);
        $this->filter($query, $filters);
        $this->search($query, $role, $list->search);

        $total = (clone $query)->count();
        $direction = $list->order === 'desc' ? 'desc' : 'asc';

        if ($list->sort === 'domainCount') {
            $query->orderByRaw('(SELECT COUNT(*) FROM `domain_admins` WHERE `domain_admins`.`username` = `admin`.`username`) '.$direction);
        } elseif ($list->sort !== 'username') {
            $query->orderBy('admin.'.$list->sortColumn(), $direction);
        }

        $query->orderBy('admin.username', $list->sort === 'username' ? $direction : 'asc');
        $rows = $query->offset($list->offset)->limit($list->limit)->get(array_map(fn (string $c): string => 'admin.'.$c, AccountRepresentation::COLUMNS))->all();

        return ['data' => $this->accounts->present($role, array_values($rows)), 'meta' => $list->meta($total)];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filter(Builder $query, array $filters): void
    {
        if (($filters['active'] ?? null) !== null) {
            $query->where('admin.active', $filters['active'] ? 1 : 0);
        }

        foreach (['forcePasswordChange' => 'force_pw_update', 'forceSecondFactorSetup' => 'force_tfa'] as $filter => $attribute) {
            if (($filters[$filter] ?? null) !== null) {
                // Legacy reads the flags with intval(...) == 1; a missing attribute is off.
                $expression = "COALESCE(JSON_VALUE(`admin`.`attributes`, '$.{$attribute}'), '0') + 0 = 1";
                $filters[$filter] ? $query->whereRaw($expression) : $query->whereRaw("NOT ({$expression})");
            }
        }

        if (($filters['secondFactorStatus'] ?? null) !== null) {
            $counted = "SELECT 1 FROM `tfa` WHERE `tfa`.`username` = `admin`.`username` AND `tfa`.`authmech` IN ('totp', 'webauthn', 'yubi_otp')";

            match ($filters['secondFactorStatus']) {
                'none' => $query->whereRaw("NOT EXISTS ({$counted})"),
                'active' => $query->whereRaw("EXISTS ({$counted} AND `tfa`.`active` = 1)"),
                default => $query->whereRaw("EXISTS ({$counted})")->whereRaw("NOT EXISTS ({$counted} AND `tfa`.`active` = 1)"),
            };
        }

        $domains = array_values(array_filter(array_map(fn (string $d): ?string => DomainName::normalize($d), $filters['domains'] ?? [])));

        if (($filters['domains'] ?? []) !== []) {
            $query->whereExists(fn (Builder $sub) => $sub->selectRaw('1')->from('domain_admins')
                ->whereColumn('domain_admins.username', 'admin.username')
                ->whereIn('domain_admins.domain', $domains === [] ? [''] : $domains));
        }

        $column = DomainAdminFlags::column((string) ($filters['permission'] ?? ''));

        if ($column !== null) {
            $set = fn (Builder $sub) => $sub->selectRaw('1')->from('da_acl')
                ->whereColumn('da_acl.username', 'admin.username')
                ->where('da_acl.'.$column, 1);
            // A missing permission record counts as every flag off (FR-041).
            ($filters['permissionValue'] ?? true) ? $query->whereExists($set) : $query->whereNotExists($set);
        }
    }

    /**
     * Usernames, and for domain administrators the assigned domain names in ASCII and readable form.
     */
    private function search(Builder $query, Role $role, ?string $search): void
    {
        if ($search === null) {
            return;
        }

        $pattern = ListQuery::likePattern(mb_strtolower($search));

        $query->where(function (Builder $inner) use ($role, $pattern, $search): void {
            $inner->whereRaw('LOWER(`admin`.`username`) LIKE ?', [$pattern]);

            if ($role !== Role::DomainAdmin) {
                return;
            }

            $inner->orWhereExists(fn (Builder $sub) => $sub->selectRaw('1')->from('domain_admins')
                ->whereColumn('domain_admins.username', 'admin.username')
                ->whereRaw('LOWER(`domain_admins`.`domain`) LIKE ?', [$pattern]));

            $unicode = $this->unicodeMatches($search);

            if ($unicode !== []) {
                $inner->orWhereExists(fn (Builder $sub) => $sub->selectRaw('1')->from('domain_admins')
                    ->whereColumn('domain_admins.username', 'admin.username')
                    ->whereIn('domain_admins.domain', $unicode));
            }
        });
    }

    /**
     * The distinct assigned names whose readable form contains a non-ASCII search term.
     *
     * @return list<string>
     */
    private function unicodeMatches(string $search): array
    {
        if (mb_check_encoding($search, 'ASCII')) {
            return [];
        }

        $needle = mb_strtolower($search);

        return DB::table('domain_admins')
            ->where('domain', 'like', ListQuery::likePattern('xn--'))
            ->distinct()
            ->pluck('domain')
            ->map(fn (mixed $domain): string => (string) $domain)
            ->filter(fn (string $domain): bool => mb_stripos(mb_strtolower(DomainName::toUnicode($domain)), $needle) !== false)
            ->values()
            ->all();
    }
}
