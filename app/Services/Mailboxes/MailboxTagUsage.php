<?php

namespace App\Services\Mailboxes;

use App\Auth\Principal;
use App\Support\Http\ListQuery;
use Illuminate\Support\Facades\DB;

/**
 * The distinct tags of the mailboxes in the caller's scope (feature 003 FR-066, research R12),
 * counted per tag without regard to letter case; the name shown is the spelling that sorts first in
 * binary order. Resources and mailboxes outside the scope never contribute.
 */
class MailboxTagUsage
{
    public const SORTS = ['name' => 'name'];

    public function __construct(private readonly MailboxScope $scope) {}

    /**
     * @return array{data: list<array{name: string, mailboxCount: int}>, meta: array{total: int, limit: int, offset: int}}
     */
    public function page(Principal $principal, ListQuery $list): array
    {
        $grouped = $this->scope->restrict(
            DB::table('tags_mailbox as t')->join('mailbox as m', 'm.username', '=', 't.username')->where('m.kind', ''),
            $principal,
            'm.domain',
        )
            ->selectRaw('MIN(`t`.`tag_name` COLLATE utf8mb4_bin) AS `name`, COUNT(DISTINCT `t`.`username`) AS `mailbox_count`')
            ->groupByRaw('LOWER(`t`.`tag_name`)');

        if ($list->search !== null) {
            $grouped->whereRaw('LOWER(`t`.`tag_name`) LIKE ?', [ListQuery::likePattern(mb_strtolower($list->search))]);
        }

        $total = DB::query()->fromSub($grouped, 'g')->count();
        $direction = $list->order === 'desc' ? 'DESC' : 'ASC';
        $rows = DB::query()->fromSub($grouped, 'g')
            ->orderByRaw("LOWER(`name`) {$direction}, `name` {$direction}")
            ->offset($list->offset)
            ->limit($list->limit)
            ->get();

        return [
            'data' => array_values($rows->map(fn (object $row): array => ['name' => (string) $row->name, 'mailboxCount' => (int) $row->mailbox_count])->all()),
            'meta' => $list->meta($total),
        ];
    }
}
