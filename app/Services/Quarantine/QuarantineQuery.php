<?php

namespace App\Services\Quarantine;

use App\Auth\Principal;
use App\Models\Mailcow\QuarantineItem;
use App\Services\Access\SpamFilterScope;
use App\Support\Http\ListQuery;
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
    /** Contract sort value => column. */
    public const SORTS = [
        'arrivedAt' => 'quarantine.created',
        'id' => 'quarantine.id',
    ];

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
     * @return array{rows: list<QuarantineItem>, total: int}
     */
    public function page(Principal $principal, ListQuery $list): array
    {
        $query = $this->base($principal);
        $total = (clone $query)->count();
        $query->orderBy($list->sortColumn(), $list->order)->orderBy('quarantine.id', 'desc');

        return ['rows' => array_values($query->offset($list->offset)->limit($list->limit)->get()->all()), 'total' => $total];
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
