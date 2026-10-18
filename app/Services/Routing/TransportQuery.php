<?php

namespace App\Services\Routing;

use App\Support\Http\ETag;
use App\Support\Http\ListQuery;
use App\Support\Routing\CredentialFingerprint;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reads of transports (feature 009 FR-031–FR-033, research R10): the list with `nextHopSharedWith`
 * from one derived table of next hop counts (the column collation groups next hops without regard
 * to letter case, as the mail server's credential lookup compares them), filters, search, sorting,
 * and the representation and version tag shared by every read and write.
 */
class TransportQuery
{
    /** contract sort value => column */
    public const SORTS = [
        'id' => 't.id',
        'destination' => 't.destination',
        'nextHop' => 't.nexthop',
        'username' => 't.username',
        'active' => 't.active',
        'mxBased' => 't.is_mx_based',
    ];

    /**
     * @param  array{active: bool|null, mxBased: bool|null, nextHop: string|null}  $filters
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}
     */
    public function page(ListQuery $list, array $filters): array
    {
        $query = $this->base();

        if ($filters['active'] !== null) {
            $query->where('t.active', $filters['active'] ? '<>' : '=', 0);
        }

        if ($filters['mxBased'] !== null) {
            $query->where('t.is_mx_based', $filters['mxBased'] ? '<>' : '=', 0);
        }

        if ($filters['nextHop'] !== null) {
            $query->where('t.nexthop', $filters['nextHop']);
        }

        $list->applySearch($query, ['t.destination', 't.nexthop', 't.username']);

        /** @var array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}} */
        return $list->paginate($query, fn (object $row): array => self::present($row), 't.id');
    }

    public function find(int $id): ?object
    {
        return $this->base()->where('t.id', $id)->first();
    }

    /** The transport of a path or bulk target ID as sent, or null when it does not exist. */
    public function resolve(string $id): ?int
    {
        if (preg_match('/^[1-9][0-9]{0,9}$/', $id) !== 1 || (int) $id > 2147483647) {
            return null;
        }

        return DB::table('transports')->where('id', (int) $id)->exists() ? (int) $id : null;
    }

    /**
     * Contract `TransportReference` entries of transports, in ID order.
     *
     * @param  list<int>  $ids
     * @return list<array{id: int, destination: string, nextHop: string, etag: string}>
     */
    public function references(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('transports')->whereIn('id', $ids)->orderBy('id')->get()
            ->map(fn (object $row): array => ['id' => (int) $row->id, 'destination' => (string) $row->destination, 'nextHop' => (string) $row->nexthop, 'etag' => self::version($row)])
            ->values()->all();
    }

    /**
     * The contract `Transport` of a row (with `shared`, the number of transports of its next hop).
     *
     * @return array<string, mixed>
     */
    public static function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'destination' => (string) $row->destination,
            'nextHop' => (string) $row->nexthop,
            'mxBased' => (int) $row->is_mx_based !== 0,
            'username' => (string) $row->username === '' ? null : (string) $row->username,
            'passwordSet' => (string) $row->password !== '',
            'active' => (int) $row->active !== 0,
            'nextHopSharedWith' => max(0, (int) ($row->shared ?? 1) - 1),
            'etag' => self::version($row),
        ];
    }

    /**
     * The version tag of the stored settings, the password only as its HMAC fingerprint; never the
     * sharing count. It changes when another transport changes the shared credentials, because
     * this row changes too.
     */
    public static function version(object $row): string
    {
        return ETag::of([
            'transport',
            (int) $row->id,
            (string) $row->destination,
            (string) $row->nexthop,
            (string) $row->username,
            CredentialFingerprint::of((string) $row->password),
            (int) $row->is_mx_based,
            (int) $row->active,
        ]);
    }

    private function base(): Builder
    {
        $shared = DB::table('transports')->selectRaw('`nexthop`, COUNT(*) AS `cnt`')->groupBy('nexthop');

        return DB::table('transports as t')
            ->leftJoinSub($shared, 's', 's.nexthop', '=', 't.nexthop')
            ->select(['t.id', 't.destination', 't.nexthop', 't.username', 't.password', 't.is_mx_based', 't.active'])
            ->selectRaw('COALESCE(`s`.`cnt`, 1) AS `shared`');
    }
}
