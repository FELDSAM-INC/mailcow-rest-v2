<?php

namespace App\Services\SenderSettings;

use App\Auth\Principal;
use App\Auth\Role;
use App\Services\Aliases\AliasAddress;
use App\Services\Aliases\AliasScope;
use App\Services\Domains\DomainScope;
use App\Support\Http\ListQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The entries a caller could add to a mailbox's explicit sender permissions (feature 004 FR-056,
 * research R15), legacy sender_acl_handles' selectable lists plus alias domains, as one paged
 * `UNION ALL`: `*` for administrators, the domains and alias domains in the caller's scope, and the
 * addresses of aliases and mailboxes in the caller's scope; left out are the mailbox itself, the
 * aliases that deliver to it, its alias-domain addresses, catch-all aliases and the entries the
 * mailbox already holds.
 */
class PermissionCandidateQuery
{
    /** Contract sort value => column of the union. */
    public const SORTS = ['type' => 'rank', 'value' => 'value'];

    public function __construct(
        private readonly DomainScope $domains,
        private readonly AliasScope $aliases,
    ) {}

    /**
     * @param  list<string>  $types
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}
     */
    public function page(Principal $principal, string $mailbox, ListQuery $list, array $types): array
    {
        $held = fn (Builder $sub, string $expression) => $sub->selectRaw('1')->from('sender_acl as h')
            ->where('h.logged_in_as', $mailbox)->where('h.external', 0)->whereRaw("`h`.`send_as` = {$expression}");
        $parts = [];
        $types = $types === [] ? [SenderEntry::ANY_ADDRESS, SenderEntry::DOMAIN, SenderEntry::ADDRESS] : $types;

        if (in_array(SenderEntry::ANY_ADDRESS, $types, true) && $principal->role === Role::Admin) {
            $parts[] = DB::query()->selectRaw("'*' AS `value`, 'anyAddress' AS `type`, 'anyAddress' AS `kind`, 0 AS `rank`")
                ->whereNotExists(fn ($sub) => $held($sub, "'*'"));
        }

        if (in_array(SenderEntry::DOMAIN, $types, true)) {
            $parts[] = $this->domains->restrict(DB::table('domain as cd'), $principal, 'cd.domain')
                ->selectRaw("CONCAT('@', `cd`.`domain`) AS `value`, 'domain' AS `type`, 'domain' AS `kind`, 1 AS `rank`")
                ->whereNotExists(fn ($sub) => $held($sub, "CONCAT('@', `cd`.`domain`)"));
            $parts[] = $this->domains->restrict(DB::table('alias_domain as cad'), $principal, 'cad.target_domain')
                ->selectRaw("CONCAT('@', `cad`.`alias_domain`) AS `value`, 'domain' AS `type`, 'aliasDomain' AS `kind`, 1 AS `rank`")
                ->whereNotExists(fn ($sub) => $held($sub, "CONCAT('@', `cad`.`alias_domain`)"));
        }

        if (in_array(SenderEntry::ADDRESS, $types, true)) {
            $parts[] = $this->aliases->restrict(DB::table('alias as a'), $principal)
                ->selectRaw("`a`.`address` AS `value`, 'address' AS `type`, IF(EXISTS (SELECT 1 FROM `mailbox` `cm` WHERE `cm`.`username` = `a`.`address`), 'mailbox', 'alias') AS `kind`, 2 AS `rank`")
                ->where('a.address', 'not like', '@%')
                ->where('a.address', '<>', $mailbox)
                ->whereRaw('NOT (`a`.`goto` REGEXP ?)', [SenderSettingsReader::deliversTo($mailbox)])
                ->whereNotIn('a.address', fn ($sub) => $sub->selectRaw("CONCAT(`am`.`local_part`, '@', `aad`.`alias_domain`)")
                    ->from('mailbox as am')->join('alias_domain as aad', 'aad.target_domain', '=', 'am.domain')->where('am.username', $mailbox))
                ->whereNotExists(fn ($sub) => $held($sub, '`a`.`address`'));
        }

        if ($parts === []) {
            return ['data' => [], 'meta' => $list->meta(0)];
        }

        $union = array_shift($parts);

        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        $query = DB::query()->fromSub($union, 'c');

        if ($list->search !== null) {
            $query->whereRaw('LOWER(`c`.`value`) LIKE ?', [ListQuery::likePattern(mb_strtolower($list->search))]);
        }

        $total = (clone $query)->count();
        $direction = $list->order === 'desc' ? 'desc' : 'asc';
        $query->orderBy('c.'.self::SORTS[$list->sort], $direction)->orderBy('c.value', $direction);
        $rows = $query->offset($list->offset)->limit($list->limit)->get(['c.value', 'c.type', 'c.kind']);

        return [
            'data' => $rows->map(fn (object $r): array => [
                'value' => (string) $r->value,
                'valueUnicode' => $r->value === '*' ? '*' : AliasAddress::unicodeOf((string) $r->value),
                'type' => (string) $r->type,
                'kind' => (string) $r->kind,
            ])->values()->all(),
            'meta' => $list->meta($total),
        ];
    }
}
