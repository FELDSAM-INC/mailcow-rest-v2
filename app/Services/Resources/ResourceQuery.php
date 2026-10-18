<?php

namespace App\Services\Resources;

use App\Auth\Principal;
use App\Services\Aliases\AliasAddress;
use App\Services\Domains\DomainScope;
use App\Support\Http\ListQuery;
use App\Support\Mailcow\UnicodeSearch;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The resource list and lookups (feature 004 FR-046, FR-047): `mailbox` rows of the resource kinds
 * (legacy `kind REGEXP 'location|thing|group'`) in domains of the caller's scope. Ties are broken by
 * the address, ascending.
 */
class ResourceQuery
{
    /** Contract sort value => column. */
    public const SORTS = [
        'address' => 'r.username',
        'description' => 'r.name',
        'kind' => 'r.kind',
        'domain' => 'r.domain',
        'bookingRule' => 'r.multiple_bookings',
        'active' => 'r.active',
        'createdAt' => 'r.created',
        'modifiedAt' => 'r.modified',
    ];

    public const KIND_PATTERN = 'location|thing|group';

    public function __construct(private readonly DomainScope $scope) {}

    public function base(Principal $principal): Builder
    {
        return $this->scope->restrict(DB::table('mailbox as r')->where('r.kind', 'regexp', self::KIND_PATTERN), $principal, 'r.domain');
    }

    /**
     * The stored address of a resource in the caller's scope, from a path or bulk target, or null.
     */
    public function resolve(Principal $principal, string $raw): ?string
    {
        $address = AliasAddress::parse($raw);

        if ($address === null || $address->catchAll) {
            return null;
        }

        $stored = $this->base($principal)->where('r.username', $address->address)->value('r.username');

        return $stored === null ? null : (string) $stored;
    }

    public function find(Principal $principal, string $address): ?object
    {
        return $this->base($principal)->where('r.username', $address)->first(self::columns());
    }

    /**
     * @param  array{domain: list<string>, kind: list<string>, active: bool|null}  $filters
     * @return array{rows: list<object>, total: int}
     */
    public function page(Principal $principal, ListQuery $list, array $filters): array
    {
        $query = $this->base($principal);

        if ($filters['domain'] !== []) {
            $query->whereIn('r.domain', $filters['domain']);
        }

        if ($filters['kind'] !== []) {
            $query->whereIn('r.kind', $filters['kind']);
        }

        if ($filters['active'] !== null) {
            $filters['active'] ? $query->where('r.active', '<>', 0) : $query->where('r.active', 0);
        }

        if ($list->search !== null) {
            $keys = UnicodeSearch::matchingKeys(clone $query, 'r.username', ['r.username'], $list->search);
            $pattern = ListQuery::likePattern(mb_strtolower($list->search));
            $query->where(function (Builder $inner) use ($pattern, $keys): void {
                $inner->whereRaw('LOWER(`r`.`name`) LIKE ?', [$pattern])->orWhereRaw('LOWER(`r`.`username`) LIKE ?', [$pattern]);

                if ($keys !== []) {
                    $inner->orWhereIn('r.username', $keys);
                }
            });
        }

        $total = (clone $query)->count();
        $query->orderBy(self::SORTS[$list->sort], $list->order === 'desc' ? 'desc' : 'asc');

        if ($list->sort !== 'address') {
            $query->orderBy('r.username');
        }

        return ['rows' => $query->offset($list->offset)->limit($list->limit)->get(self::columns())->all(), 'total' => $total];
    }

    /**
     * @return list<string>
     */
    public static function columns(): array
    {
        return ['r.username', 'r.name', 'r.kind', 'r.multiple_bookings', 'r.active', 'r.domain', 'r.created', 'r.modified'];
    }
}
