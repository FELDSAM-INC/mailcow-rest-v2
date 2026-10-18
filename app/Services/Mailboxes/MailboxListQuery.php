<?php

namespace App\Services\Mailboxes;

use App\Auth\Principal;
use App\Services\Config\MailcowConfig;
use App\Support\Domain\DomainName;
use App\Support\Http\ListQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The mailbox list (feature 003 FR-012–FR-016, research R10): one page of `mailbox` rows (never
 * resources) in the caller's scope, filtered by domain, status, tag and authentication source,
 * searched in the address, the display name and the tags, and sorted. Mailboxes without a value for
 * the sort field come last in both directions; ties are broken by address. Sorting joins only what
 * it needs, so a page costs two statements; MailboxReader adds the rest of the representation.
 */
class MailboxListQuery
{
    /**
     * Contract sort value => SQL expression (`q` is the joined usage record). The last mail login is
     * a correlated subquery on the indexed `sasl_log.username`: a join with a grouped derived table
     * would scan it once per mailbox.
     */
    public const SORTS = [
        'address' => '`m`.`username`',
        'displayName' => '`m`.`name`',
        'domain' => '`m`.`domain`',
        'status' => '`m`.`active`',
        'quota' => '`m`.`quota`',
        'quotaUsed' => 'COALESCE(`q`.`bytes`, 0)',
        'percentUsed' => 'CASE WHEN `m`.`quota` = 0 THEN NULL ELSE CAST(COALESCE(`q`.`bytes`, 0) AS DOUBLE) / `m`.`quota` END',
        'messageCount' => 'COALESCE(`q`.`messages`, 0)',
        'lastMailLogin' => "(SELECT MAX(`l`.`datetime`) FROM `sasl_log` `l` WHERE `l`.`username` = `m`.`username` AND `l`.`service` IN ('imap', 'pop3', 'smtp'))",
        'passwordChangedAt' => "CASE WHEN JSON_VALUE(`m`.`attributes`, '$.passwd_update') REGEXP '^[0-9]+$' THEN FROM_UNIXTIME(JSON_VALUE(`m`.`attributes`, '$.passwd_update')) ELSE STR_TO_DATE(JSON_VALUE(`m`.`attributes`, '$.passwd_update'), '%Y-%m-%d %H:%i:%s') END",
        'createdAt' => '`m`.`created`',
        'modifiedAt' => '`m`.`modified`',
    ];

    public function __construct(
        private readonly MailboxScope $scope,
        private readonly MailcowConfig $config,
    ) {}

    /**
     * @param  array{domains: list<string>, statuses: list<string>, tags: list<string>, authSources: list<string>}  $filters
     * @return array{rows: list<object>, total: int}
     */
    public function page(Principal $principal, ListQuery $list, array $filters): array
    {
        $base = $this->filtered($principal, $list, $filters);
        $total = (clone $base)->count();
        $query = (clone $base)->select('m.*');
        $expression = self::SORTS[$list->sort];

        if (in_array($list->sort, ['quotaUsed', 'percentUsed', 'messageCount'], true)) {
            $query->leftJoin(config('restv2.master') ? 'quota2 as q' : 'quota2replica as q', 'q.username', '=', 'm.username');
        } elseif ($list->sort === 'lastMailLogin' && ! $this->config->showLastLogin()) {
            // Last logins are not reported, so they cannot order the list either.
            $expression = 'NULL';
        }

        $direction = $list->order === 'desc' ? 'DESC' : 'ASC';
        $rows = $query->orderByRaw("({$expression}) IS NULL ASC, {$expression} {$direction}, `m`.`username` ASC")
            ->offset($list->offset)
            ->limit($list->limit)
            ->get()
            ->all();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @param  array{domains: list<string>, statuses: list<string>, tags: list<string>, authSources: list<string>}  $filters
     */
    private function filtered(Principal $principal, ListQuery $list, array $filters): Builder
    {
        $query = $this->scope->restrict(DB::table('mailbox as m')->where('m.kind', ''), $principal, 'm.domain');

        if ($filters['domains'] !== []) {
            $domains = array_values(array_filter(array_map(fn (string $d): ?string => DomainName::normalize($d), $filters['domains'])));
            $domains === [] ? $query->whereRaw('1 = 0') : $query->whereIn('m.domain', $domains);
        }

        if ($filters['statuses'] !== []) {
            $query->whereIn('m.active', array_values(array_intersect_key(MailboxValues::STATUSES, array_flip($filters['statuses']))));
        }

        if ($filters['authSources'] !== []) {
            $sources = array_values(array_intersect_key(MailboxValues::AUTH_SOURCES, array_flip($filters['authSources'])));
            $query->where(function (Builder $q) use ($sources): void {
                $q->whereIn('m.authsource', $sources);

                if (in_array('mailcow', $sources, true)) {
                    $q->orWhereNull('m.authsource');
                }
            });
        }

        if ($filters['tags'] !== []) {
            $tags = array_values(array_unique(array_map(fn (string $t): string => mb_strtolower(trim($t)), $filters['tags'])));
            $query->whereExists(fn (Builder $sub) => $sub->selectRaw('1')->from('tags_mailbox as tf')
                ->whereColumn('tf.username', 'm.username')
                ->whereIn(DB::raw('LOWER(`tf`.`tag_name`)'), $tags));
        }

        if ($list->search !== null) {
            $pattern = ListQuery::likePattern(mb_strtolower($list->search));
            $query->where(function (Builder $q) use ($pattern): void {
                $q->whereRaw('LOWER(`m`.`username`) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(`m`.`name`) LIKE ?', [$pattern])
                    ->orWhereExists(fn (Builder $sub) => $sub->selectRaw('1')->from('tags_mailbox as ts')
                        ->whereColumn('ts.username', 'm.username')
                        ->whereRaw('LOWER(`ts`.`tag_name`) LIKE ?', [$pattern]));
            });
        }

        return $query;
    }
}
