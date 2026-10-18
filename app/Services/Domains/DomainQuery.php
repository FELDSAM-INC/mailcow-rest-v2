<?php

namespace App\Services\Domains;

use App\Auth\Principal;
use App\Support\Domain\DomainName;
use App\Support\Http\ListQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The domain list (FR-011–FR-015, research D6): one page of names in the caller's scope, searched,
 * filtered and sorted, ties by name ascending. Sorting by a usage figure joins the grouped figure
 * only when asked for; the figures of the page itself come from DomainUsageCalculator.
 */
class DomainQuery
{
    /** Contract sort value => SQL expression. */
    public const SORTS = [
        'name' => '`d`.`domain`',
        'description' => '`d`.`description`',
        'active' => '`d`.`active`',
        'backupMx' => '`d`.`backupmx`',
        'mailboxes' => 'COALESCE(`u_mbox`.`mailboxes`, 0)',
        'aliases' => 'COALESCE(`u_alias`.`aliases`, 0)',
        'quotaAllocated' => 'COALESCE(`u_mbox`.`allocated`, 0)',
        'storageUsed' => 'COALESCE(`u_storage`.`bytes`, 0)',
        'maxMailboxes' => '`d`.`mailboxes`',
        'maxAliases' => '`d`.`aliases`',
        'defaultMailboxQuota' => '`d`.`defquota`',
        'maxMailboxQuota' => '`d`.`maxquota`',
        'domainQuota' => '`d`.`quota`',
        'createdAt' => '`d`.`created`',
        'modifiedAt' => '`d`.`modified`',
    ];

    public function __construct(private readonly DomainScope $scope) {}

    /**
     * @param  array{active: bool|null, backupMx: bool|null, tags: list<string>}  $filters
     * @return array{rows: list<object>, total: int}
     */
    public function page(Principal $principal, ListQuery $list, array $filters): array
    {
        $base = $this->filtered($principal, $list, $filters);
        $total = (clone $base)->count();

        $query = (clone $base)->select('d.*');
        $this->joinSortFigure($query, $list->sort);
        $query->orderByRaw(self::SORTS[$list->sort].' '.($list->order === 'desc' ? 'DESC' : 'ASC'))
            ->orderBy('d.domain')
            ->offset($list->offset)
            ->limit($list->limit);

        return ['rows' => $query->get()->all(), 'total' => $total];
    }

    /**
     * @param  array{active: bool|null, backupMx: bool|null, tags: list<string>}  $filters
     */
    private function filtered(Principal $principal, ListQuery $list, array $filters): Builder
    {
        $query = $this->scope->restrict(DB::table('domain as d'), $principal, 'd.domain');

        if ($filters['active'] !== null) {
            $query->where('d.active', $filters['active'] ? 1 : 0);
        }

        if ($filters['backupMx'] !== null) {
            $query->where('d.backupmx', $filters['backupMx'] ? 1 : 0);
        }

        if ($filters['tags'] !== []) {
            $tags = $filters['tags'];
            $query->whereExists(fn (Builder $sub) => $sub->selectRaw('1')->from('tags_domain as tf')
                ->whereColumn('tf.domain', 'd.domain')
                ->whereIn('tf.tag_name', $tags));
        }

        if ($list->search !== null) {
            $pattern = ListQuery::likePattern($list->search);
            $unicode = $this->unicodeMatches($principal, $list->search);

            $query->where(function (Builder $q) use ($pattern, $unicode): void {
                $q->where('d.domain', 'like', $pattern)
                    ->orWhere('d.description', 'like', $pattern)
                    ->orWhereExists(fn (Builder $sub) => $sub->selectRaw('1')->from('tags_domain as ts')
                        ->whereColumn('ts.domain', 'd.domain')
                        ->where('ts.tag_name', 'like', $pattern));

                if ($unicode !== []) {
                    $q->orWhereIn('d.domain', $unicode);
                }
            });
        }

        return $query;
    }

    /**
     * Punycode names in scope whose readable form contains the search term.
     *
     * @return list<string>
     */
    private function unicodeMatches(Principal $principal, string $search): array
    {
        return $this->scope->restrict(DB::table('domain as d'), $principal, 'd.domain')
            ->where('d.domain', 'like', '%xn--%')
            ->pluck('d.domain')
            ->map(fn (mixed $name): string => (string) $name)
            ->filter(fn (string $name): bool => mb_stripos(DomainName::toUnicode($name), $search) !== false)
            ->values()
            ->all();
    }

    private function joinSortFigure(Builder $query, string $sort): void
    {
        match ($sort) {
            'mailboxes', 'quotaAllocated' => $query->leftJoinSub(
                DB::table('mailbox')->groupBy('domain')->select([
                    'domain',
                    DB::raw("SUM(`kind` = '') AS `mailboxes`"),
                    DB::raw("COALESCE(SUM(CASE WHEN `kind` = '' THEN `quota` END), 0) AS `allocated`"),
                ]),
                'u_mbox', 'u_mbox.domain', '=', 'd.domain',
            ),
            'aliases' => $query->leftJoinSub(
                DB::table('alias as a')
                    ->leftJoin('alias_domain as ad', 'ad.alias_domain', '=', 'a.domain')
                    ->whereNotIn('a.address', fn (Builder $sub) => $sub->select('username')->from('mailbox'))
                    ->groupBy('target')
                    ->select([DB::raw('COALESCE(`ad`.`target_domain`, `a`.`domain`) AS `target`'), DB::raw('COUNT(*) AS `aliases`')]),
                'u_alias', 'u_alias.target', '=', 'd.domain',
            ),
            'storageUsed' => $query->leftJoinSub(
                DB::table('quota2 as q')->join('mailbox as m', 'm.username', '=', 'q.username')
                    ->groupBy('m.domain')
                    ->select(['m.domain', DB::raw('SUM(`q`.`bytes`) AS `bytes`')]),
                'u_storage', 'u_storage.domain', '=', 'd.domain',
            ),
            default => null,
        };
    }
}
