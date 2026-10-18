<?php

namespace App\Services\AliasDomains;

use App\Auth\Principal;
use App\Services\Domains\DomainScope;
use App\Support\Domain\DomainName;
use App\Support\Http\ListQuery;
use App\Support\Mailcow\UnicodeSearch;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The alias domain list and lookups (feature 004 FR-038, FR-039, research R19): alias domains whose
 * target is in the caller's scope (002's DomainScope), with the target's relay flag and the number
 * of aliases that live in the alias domain. Ties are broken by the name, ascending.
 */
class AliasDomainQuery
{
    /** Contract sort value => column. */
    public const SORTS = [
        'name' => 'ad.alias_domain',
        'targetDomain' => 'ad.target_domain',
        'active' => 'ad.active',
        'createdAt' => 'ad.created',
        'modifiedAt' => 'ad.modified',
    ];

    public function __construct(private readonly DomainScope $scope) {}

    public function base(Principal $principal): Builder
    {
        $query = DB::table('alias_domain as ad')->leftJoin('domain as t', 't.domain', '=', 'ad.target_domain');

        return $this->scope->restrict($query, $principal, 'ad.target_domain');
    }

    /**
     * The normalised name of an alias domain in the caller's scope, from a path or bulk target, or
     * null (answered "not found").
     */
    public function resolve(Principal $principal, string $raw): ?string
    {
        $name = DomainName::normalizeValid($raw);

        return $name !== null && $this->base($principal)->where('ad.alias_domain', $name)->exists() ? $name : null;
    }

    public function find(Principal $principal, string $name): ?object
    {
        return $this->base($principal)->where('ad.alias_domain', $name)->first(self::columns());
    }

    /**
     * @param  array{targetDomain: list<string>, active: bool|null}  $filters
     * @return array{rows: list<object>, total: int}
     */
    public function page(Principal $principal, ListQuery $list, array $filters): array
    {
        $query = $this->base($principal);

        if ($filters['targetDomain'] !== []) {
            $query->whereIn('ad.target_domain', $filters['targetDomain']);
        }

        if ($filters['active'] !== null) {
            $filters['active'] ? $query->where('ad.active', '<>', 0) : $query->where('ad.active', 0);
        }

        if ($list->search !== null) {
            $names = UnicodeSearch::matchingKeys(clone $query, 'ad.alias_domain', ['ad.alias_domain', 'ad.target_domain'], $list->search);
            $pattern = ListQuery::likePattern(mb_strtolower($list->search));
            $query->where(function (Builder $inner) use ($pattern, $names): void {
                $inner->whereRaw('LOWER(`ad`.`alias_domain`) LIKE ?', [$pattern])->orWhereRaw('LOWER(`ad`.`target_domain`) LIKE ?', [$pattern]);

                if ($names !== []) {
                    $inner->orWhereIn('ad.alias_domain', $names);
                }
            });
        }

        $total = (clone $query)->count();
        $query->orderBy(self::SORTS[$list->sort], $list->order === 'desc' ? 'desc' : 'asc');

        if ($list->sort !== 'name') {
            $query->orderBy('ad.alias_domain');
        }

        return ['rows' => $query->offset($list->offset)->limit($list->limit)->get(self::columns())->all(), 'total' => $total];
    }

    /**
     * @return list<mixed>
     */
    public static function columns(): array
    {
        return [
            'ad.*',
            't.backupmx as target_backupmx',
            DB::raw('(SELECT COUNT(*) FROM `alias` `ac` WHERE `ac`.`domain` = `ad`.`alias_domain`) AS `alias_count`'),
        ];
    }
}
