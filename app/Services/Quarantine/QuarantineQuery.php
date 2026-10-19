<?php

namespace App\Services\Quarantine;

use App\Auth\Principal;
use App\Casts\MailcowDateTime;
use App\Models\Mailcow\QuarantineItem;
use App\Services\Access\SpamFilterScope;
use App\Support\Http\ListQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The quarantine items in a caller's scope (feature 006 research R16, FR-007 to FR-011): one
 * `SELECT` and one `COUNT(*)` with the same predicates, never selecting the stored message in a
 * list (only `LENGTH(msg)` and, for actions and downloads, the message of one item). The virus
 * flag is `LOCATE('VIRUS_FOUND', symbols) > 0`. Lists are newest first by default; ties are always
 * broken by the identifier, newest first.
 */
class QuarantineQuery
{
    /** Contract sort value => column (`virus` is the computed flag). */
    public const SORTS = [
        'arrivedAt' => 'quarantine.created',
        'id' => 'quarantine.id',
        'queueId' => 'quarantine.qid',
        'score' => 'quarantine.score',
        'sender' => 'quarantine.sender',
        'recipient' => 'quarantine.rcpt',
        'subject' => 'quarantine.subject',
        'action' => 'quarantine.action',
        'virus' => 'virus',
        'notified' => 'quarantine.notified',
    ];

    /** Contract action => stored action; `other` is any other stored value. */
    public const ACTIONS = ['rejected' => 'reject', 'junk' => 'add header', 'subjectRewritten' => 'rewrite subject'];

    public const LIST_COLUMNS = ['id', 'qid', 'subject', 'score', 'sender', 'rcpt', 'action', 'created', 'notified'];

    public const DETAIL_COLUMNS = ['ip', 'symbols', 'fuzzy_hashes', 'user'];

    public function __construct(private readonly SpamFilterScope $scope) {}

    /**
     * @return Builder<QuarantineItem>
     */
    public function base(Principal $principal): Builder
    {
        $query = QuarantineItem::query()
            ->select(array_map(fn (string $c): string => 'quarantine.'.$c, self::LIST_COLUMNS))
            ->selectRaw("IFNULL(LOCATE('VIRUS_FOUND', `quarantine`.`symbols`), 0) > 0 AS `virus`");
        ($this->scope->itemScope($principal))($query->getQuery());

        return $query;
    }

    /**
     * @param  array{recipient?: string|null, domain?: string|null, actions?: list<string>, virus?: bool|null, notified?: bool|null, from?: CarbonImmutable|null, to?: CarbonImmutable|null, minScore?: float|null, maxScore?: float|null}  $filters
     * @return array{rows: list<QuarantineItem>, total: int}
     */
    public function page(Principal $principal, ListQuery $list, array $filters = []): array
    {
        $query = $this->base($principal);
        $this->filter($query, $filters);
        $list->applySearch($query, ['quarantine.subject', 'quarantine.sender', 'quarantine.rcpt', 'quarantine.qid']);
        $total = (clone $query)->count();
        $query->orderBy($list->sortColumn(), $list->order)->orderBy('quarantine.id', 'desc');

        return ['rows' => array_values($query->offset($list->offset)->limit($list->limit)->get()->all()), 'total' => $total];
    }

    /**
     * The filters of research R16; times are compared in mailcow's local time zone (TZ), in which
     * `created` is stored; items without a score never match a score range.
     *
     * @param  Builder<QuarantineItem>  $query
     * @param  array<string, mixed>  $filters
     */
    private function filter(Builder $query, array $filters): void
    {
        if (is_string($filters['recipient'] ?? null)) {
            $query->where('quarantine.rcpt', $filters['recipient']);
        }

        if (is_string($filters['domain'] ?? null)) {
            $query->whereRaw("SUBSTRING_INDEX(`quarantine`.`rcpt`, '@', -1) = ?", [$filters['domain']]);
        }

        $actions = (array) ($filters['actions'] ?? []);

        if ($actions !== []) {
            $query->where(function (Builder $inner) use ($actions): void {
                $stored = array_values(array_intersect_key(self::ACTIONS, array_flip($actions)));

                if ($stored !== []) {
                    $inner->orWhereIn('quarantine.action', $stored);
                }

                if (in_array('other', $actions, true)) {
                    $inner->orWhereNotIn('quarantine.action', array_values(self::ACTIONS));
                }
            });
        }

        if (is_bool($filters['virus'] ?? null)) {
            $query->whereRaw("IFNULL(LOCATE('VIRUS_FOUND', `quarantine`.`symbols`), 0) ".($filters['virus'] ? '> 0' : '= 0'));
        }

        if (is_bool($filters['notified'] ?? null)) {
            $filters['notified'] ? $query->where('quarantine.notified', '<>', 0) : $query->where('quarantine.notified', 0);
        }

        foreach (['from' => '>=', 'to' => '<'] as $name => $operator) {
            if (($filters[$name] ?? null) instanceof CarbonImmutable) {
                $query->where('quarantine.created', $operator, $filters[$name]->setTimezone(MailcowDateTime::zone())->format('Y-m-d H:i:s'));
            }
        }

        foreach (['minScore' => '>=', 'maxScore' => '<='] as $name => $operator) {
            if (is_float($filters[$name] ?? null)) {
                $query->where('quarantine.score', $operator, $filters[$name]);
            }
        }
    }

    /**
     * One item in the caller's scope with the spam filter's results and the stored size, and the
     * stored message when asked for; null when it does not exist or is outside the scope.
     */
    public function find(Principal $principal, int $id, bool $withMessage = false): ?QuarantineItem
    {
        $query = $this->base($principal)
            ->addSelect(array_map(fn (string $c): string => 'quarantine.'.$c, self::DETAIL_COLUMNS))
            ->selectRaw('IFNULL(LENGTH(`quarantine`.`msg`), 0) AS `size`')
            ->where('quarantine.id', $id);

        if ($withMessage) {
            $query->addSelect('quarantine.msg');
        }

        return $query->first();
    }

    /** Whether an item exists in the caller's scope (the re-check of a busy item). */
    public function exists(Principal $principal, int $id): bool
    {
        $query = DB::table('quarantine')->where('quarantine.id', $id);
        ($this->scope->itemScope($principal))($query);

        return $query->exists();
    }
}
