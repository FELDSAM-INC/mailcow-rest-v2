<?php

namespace App\Services\Aliases;

use App\Auth\Principal;
use App\Auth\Role;
use App\Support\Http\ListQuery;
use App\Support\Mailcow\UnicodeSearch;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The alias list and lookups (feature 004 FR-011–FR-019, research R2, R19): `alias` rows that are
 * not a mailbox's own row, in the caller's scope, with the derived columns of an alias domain
 * (`ad_target`) and of an existing domain (`d_domain`) joined. The default page walks the primary
 * key; ties are broken by the identifier in the requested direction.
 */
class AliasQuery
{
    /** Contract sort value => column. */
    public const SORTS = [
        'id' => 'a.id',
        'address' => 'a.address',
        'domain' => 'a.domain',
        'active' => 'a.active',
        'groupwareVisible' => 'a.sogo_visible',
        'internal' => 'a.internal',
        'senderAllowed' => 'a.sender_allowed',
        'createdAt' => 'a.created',
        'modifiedAt' => 'a.modified',
    ];

    public function __construct(private readonly AliasScope $scope) {}

    /**
     * Aliases in the caller's scope with the derived columns. The exclusion of mailboxes' own rows
     * comes last, so that MariaDB evaluates it only for rows that pass the other conditions.
     */
    public function base(Principal $principal): Builder
    {
        return self::withDerived(self::notOwn($this->scope->restrict(DB::table('alias as a'), $principal)));
    }

    /** One alias in the caller's scope with its derived columns, or null. */
    public function find(Principal $principal, int $id): ?object
    {
        $query = self::notOwn($this->scope->restrict(DB::table('alias as a')->where('a.id', $id), $principal));

        return self::withDerived($query)->first(self::columns());
    }

    /**
     * The identifier of an alias in the caller's scope from a path or bulk target, or null.
     */
    public function resolve(Principal $principal, string $raw): ?int
    {
        $raw = trim($raw);

        if (preg_match('/^[0-9]{1,10}$/', $raw) !== 1 || (int) $raw < 1 || (int) $raw > 2147483647) {
            return null;
        }

        return self::notOwn($this->scope->restrict(DB::table('alias as a')->where('a.id', (int) $raw), $principal))->exists() ? (int) $raw : null;
    }

    /**
     * @param  array{address: list<string>, domain: list<string>, primaryDomain: list<string>, kind: list<string>, destinationType: list<string>, destination: list<string>, active: bool|null, senderAllowed: bool|null, groupwareVisible: bool|null, internal: bool|null}  $filters
     * @return array{rows: list<object>, total: int}
     */
    public function page(Principal $principal, ListQuery $list, array $filters): array
    {
        $query = $this->filtered($principal, $filters);

        if ($list->search !== null) {
            $ids = UnicodeSearch::matchingKeys($this->filtered($principal, $filters), 'a.id', ['a.address'], $list->search);
            $pattern = ListQuery::likePattern(mb_strtolower($list->search));
            $query->where(function (Builder $inner) use ($pattern, $ids): void {
                foreach (['a.address', 'a.goto', 'a.public_comment', 'a.private_comment'] as $column) {
                    $inner->orWhereRaw('LOWER('.$inner->getGrammar()->wrap($column).') LIKE ?', [$pattern]);
                }

                if ($ids !== []) {
                    $inner->orWhereIn('a.id', $ids);
                }
            });
        }

        $total = $this->count($principal, $query, $filters, $list->search === null);
        $direction = $list->order === 'desc' ? 'desc' : 'asc';
        $query = self::withDerived(self::notOwn($query))->orderBy(self::SORTS[$list->sort], $direction);

        if ($list->sort !== 'id') {
            $query->orderBy('a.id', $direction);
        }

        return ['rows' => $query->offset($list->offset)->limit($list->limit)->get(self::columns())->all(), 'total' => $total];
    }

    /**
     * The number of matching aliases. Without any condition (an administrator's unfiltered list),
     * all rows minus the mailboxes' own rows is counted, which reads the primary keys instead of
     * looking up every alias in `mailbox` (SC-005).
     *
     * @param  array<string, mixed>  $filters
     */
    private function count(Principal $principal, Builder $query, array $filters, bool $noSearch): int
    {
        $unfiltered = $noSearch && $principal->role === Role::Admin
            && array_filter($filters, fn (mixed $f): bool => $f !== [] && $f !== null) === [];

        if ($unfiltered) {
            return DB::table('alias')->count() - DB::table('alias as a')->join('mailbox as own', 'own.username', '=', 'a.address')->count();
        }

        return self::notOwn(clone $query)->count();
    }

    private static function notOwn(Builder $query): Builder
    {
        return $query->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from('mailbox as own')->whereColumn('own.username', 'a.address'));
    }

    private static function withDerived(Builder $query): Builder
    {
        return $query->leftJoin('alias_domain as ad', 'ad.alias_domain', '=', 'a.domain')->leftJoin('domain as d', 'd.domain', '=', 'a.domain');
    }

    /**
     * @return list<string>
     */
    public static function columns(): array
    {
        return ['a.*', 'ad.target_domain as ad_target', 'd.domain as d_domain'];
    }

    /**
     * @param  array{address: list<string>, domain: list<string>, primaryDomain: list<string>, kind: list<string>, destinationType: list<string>, destination: list<string>, active: bool|null, senderAllowed: bool|null, groupwareVisible: bool|null, internal: bool|null}  $filters
     */
    private function filtered(Principal $principal, array $filters): Builder
    {
        $query = $this->scope->restrict(DB::table('alias as a'), $principal);

        if ($filters['address'] !== []) {
            $query->whereIn('a.address', $filters['address']);
        }

        if ($filters['domain'] !== []) {
            $query->whereIn('a.domain', $filters['domain']);
        }

        if ($filters['primaryDomain'] !== []) {
            $primary = $filters['primaryDomain'];
            $query->where(fn (Builder $w) => $w->whereIn('a.domain', $primary)
                ->orWhereIn('a.domain', fn ($sub) => $sub->select('alias_domain')->from('alias_domain')->whereIn('target_domain', $primary)));
        }

        $kinds = array_unique($filters['kind']);

        if (count($kinds) === 1) {
            $kinds === ['catchAll'] ? $query->where('a.address', 'like', '@%') : $query->where('a.address', 'not like', '@%');
        }

        $types = array_unique($filters['destinationType']);

        if ($types !== [] && count($types) < 4) {
            $special = array_values(array_intersect_key(AliasDestination::SPECIAL, array_flip($types)));
            $query->where(function (Builder $w) use ($types, $special): void {
                if ($special !== []) {
                    $w->orWhereIn('a.goto', $special);
                }

                if (in_array(AliasDestination::ADDRESSES, $types, true)) {
                    $w->orWhereNotIn('a.goto', array_values(AliasDestination::SPECIAL));
                }
            });
        }

        if ($filters['destination'] !== []) {
            $destinations = $filters['destination'];
            $query->where(function (Builder $w) use ($destinations): void {
                foreach ($destinations as $destination) {
                    $w->orWhereRaw('FIND_IN_SET(?, `a`.`goto`) > 0', [$destination]);
                }
            });
        }

        foreach (['active' => 'a.active', 'senderAllowed' => 'a.sender_allowed', 'groupwareVisible' => 'a.sogo_visible', 'internal' => 'a.internal'] as $filter => $column) {
            if ($filters[$filter] !== null) {
                $filters[$filter] ? $query->where($column, '<>', 0) : $query->where($column, 0);
            }
        }

        return $query;
    }
}
