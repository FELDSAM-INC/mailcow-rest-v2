<?php

namespace App\Services\Domains;

use App\Auth\Principal;
use App\Support\Domain\DomainName;
use App\Support\Http\ListQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The domain list (FR-011–FR-015, research D6): one page of names in the caller's scope, searched,
 * filtered and sorted, ties by name ascending. Sorting by a usage figure computes it per filtered
 * domain with an indexed subquery; the figures of the page itself come from DomainUsageCalculator.
 */
class DomainQuery
{
    /**
     * Contract sort value => SQL expression. The usage figures are correlated subqueries on the
     * indexed columns (`mailbox.domain`, `alias.domain`, `alias_domain.target_domain`, the primary
     * keys), evaluated for the filtered domains only (SC-005).
     */
    public const SORTS = [
        'name' => '`d`.`domain`',
        'description' => '`d`.`description`',
        'active' => '`d`.`active`',
        'backupMx' => '`d`.`backupmx`',
        'mailboxes' => "(SELECT COUNT(*) FROM `mailbox` `sm` WHERE `sm`.`domain` = `d`.`domain` AND `sm`.`kind` = '')",
        'aliases' => '((SELECT COUNT(*) FROM `alias` `sa` WHERE `sa`.`domain` = `d`.`domain` AND NOT EXISTS (SELECT 1 FROM `mailbox` `sx` WHERE `sx`.`username` = `sa`.`address`))'
            .' + (SELECT COUNT(*) FROM `alias_domain` `sd` JOIN `alias` `sb` ON `sb`.`domain` = `sd`.`alias_domain` WHERE `sd`.`target_domain` = `d`.`domain` AND NOT EXISTS (SELECT 1 FROM `mailbox` `sy` WHERE `sy`.`username` = `sb`.`address`)))',
        'quotaAllocated' => "(SELECT COALESCE(SUM(`sq`.`quota`), 0) FROM `mailbox` `sq` WHERE `sq`.`domain` = `d`.`domain` AND `sq`.`kind` = '')",
        'storageUsed' => '(SELECT COALESCE(SUM(`q2`.`bytes`), 0) FROM `mailbox` `sm2` JOIN `quota2` `q2` ON `q2`.`username` = `sm2`.`username` WHERE `sm2`.`domain` = `d`.`domain`)',
        'maxMailboxes' => '`d`.`mailboxes`',
        'maxAliases' => '`d`.`aliases`',
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
}
