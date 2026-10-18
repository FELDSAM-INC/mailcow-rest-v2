<?php

namespace App\Services\Routing;

use App\Auth\Principal;
use App\Support\Http\ListQuery;
use Illuminate\Support\Facades\DB;

/**
 * The BCC destination choices (feature 009 FR-057, research R18): exactly the local destinations
 * BccDestinationResolver accepts, from the caller's scope, as one `UNION ALL` filtered in SQL
 * (domains, alias domains, mailboxes, aliases that are neither catch-alls nor mailboxes' own
 * aliases, and mailboxes' local parts at their domain's alias domains), with `deliversTo` filled
 * for the page in two more queries. Legacy's `get/bcc-destination-options` returned every object of
 * the server in one response.
 */
class BccDestinationChoiceQuery
{
    public const KINDS = ['domain', 'aliasDomain', 'mailbox', 'alias', 'aliasDomainAddress'];

    /** contract sort value => column; `kind` orders by the kinds' rank, then by value */
    public const SORTS = ['kind' => 'u.rank', 'value' => 'u.value'];

    public function __construct(private readonly BccScope $scope) {}

    /**
     * @param  array{kind: list<string>, domain: list<string>}  $filters
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}
     */
    public function page(Principal $principal, ListQuery $list, array $filters): array
    {
        $parts = [
            $this->scope->restrict(DB::table('domain')->selectRaw("1 AS `rank`, 'domain' AS `kind`, CONCAT('@', `domain`.`domain`) AS `value`, `domain`.`domain` AS `dom`"), $principal, 'domain.domain'),
            $this->scope->restrict(DB::table('alias_domain')->selectRaw("2 AS `rank`, 'aliasDomain' AS `kind`, CONCAT('@', `alias_domain`.`alias_domain`) AS `value`, `alias_domain`.`alias_domain` AS `dom`"), $principal, 'alias_domain.alias_domain'),
            $this->scope->restrict(DB::table('mailbox')->selectRaw("3 AS `rank`, 'mailbox' AS `kind`, `mailbox`.`username` AS `value`, `mailbox`.`domain` AS `dom`")->where('mailbox.kind', ''), $principal, 'mailbox.domain'),
            $this->scope->restrict(DB::table('alias')->selectRaw("4 AS `rank`, 'alias' AS `kind`, `alias`.`address` AS `value`, `alias`.`domain` AS `dom`")
                ->where('alias.address', 'not like', '@%')
                ->whereColumn('alias.goto', '<>', 'alias.address')
                ->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from('mailbox as own')->whereColumn('own.username', 'alias.address')), $principal, 'alias.domain'),
            $this->scope->restrict(DB::table('mailbox as m')->join('alias_domain as ad', 'ad.target_domain', '=', 'm.domain')
                ->selectRaw("5 AS `rank`, 'aliasDomainAddress' AS `kind`, CONCAT(`m`.`local_part`, '@', `ad`.`alias_domain`) AS `value`, `ad`.`alias_domain` AS `dom`")
                ->where('m.kind', ''), $principal, 'ad.alias_domain'),
        ];

        $union = array_shift($parts);

        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        $query = DB::query()->fromSub($union, 'u')->select(['u.rank', 'u.kind', 'u.value', 'u.dom']);

        if ($filters['kind'] !== []) {
            $query->whereIn('u.kind', $filters['kind']);
        }

        if ($filters['domain'] !== []) {
            $query->whereIn('u.dom', $filters['domain']);
        }

        $list->applySearch($query, ['u.value']);
        $page = $list->paginate($query, null, 'u.value');
        $rows = $page['data'];
        $deliversTo = $this->deliversTo($principal, $rows);

        return [
            'data' => array_map(fn (object $row): array => [
                'kind' => (string) $row->kind,
                'value' => (string) $row->value,
                'domain' => (string) $row->dom,
                'deliversTo' => $deliversTo[(string) $row->value] ?? [],
            ], $rows),
            'meta' => $page['meta'],
        ];
    }

    /**
     * The mailboxes in scope that the page's aliases and alias-domain addresses deliver to.
     *
     * @param  list<mixed>  $rows
     * @return array<string, list<string>>
     */
    private function deliversTo(Principal $principal, array $rows): array
    {
        $aliases = [];
        $addresses = [];
        $result = [];

        foreach ($rows as $row) {
            if (is_object($row) && $row->kind === 'alias') {
                $aliases[] = (string) $row->value;
            } elseif (is_object($row) && $row->kind === 'aliasDomainAddress') {
                $addresses[(string) $row->value] = (string) $row->dom;
            }
        }

        if ($addresses !== []) {
            $targets = DB::table('alias_domain')->whereIn('alias_domain', array_values(array_unique($addresses)))->pluck('target_domain', 'alias_domain');

            foreach ($addresses as $value => $aliasDomain) {
                $result[$value] = [substr($value, 0, (int) strrpos($value, '@')).'@'.$targets->get($aliasDomain)];
            }
        }

        if ($aliases === []) {
            return $result;
        }

        $gotos = DB::table('alias')->whereIn('address', $aliases)->pluck('goto', 'address')->map(fn (mixed $goto): array => array_values(array_filter(array_map('trim', explode(',', (string) $goto)))))->all();
        $candidates = array_values(array_unique(array_merge([], ...array_values($gotos))));
        $inScope = $candidates === [] ? [] : $this->scope->restrict(DB::table('mailbox')->where('kind', '')->whereIn('username', $candidates), $principal, 'mailbox.domain')->pluck('username')->map(fn (mixed $u): string => (string) $u)->all();

        foreach ($gotos as $address => $destinations) {
            $result[(string) $address] = array_values(array_filter($destinations, fn (string $d): bool => in_array($d, $inScope, true)));
        }

        return $result;
    }
}
