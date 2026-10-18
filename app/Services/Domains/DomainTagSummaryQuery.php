<?php

namespace App\Services\Domains;

use App\Auth\Principal;
use App\Support\Http\ListQuery;
use Illuminate\Support\Facades\DB;

/**
 * The distinct tags of the domains in the caller's scope (FR-066, research D23), counted per tag
 * without regard to letter case; the name shown is the alphabetically first spelling. Tags that
 * appear only on domains outside the scope never show.
 */
class DomainTagSummaryQuery
{
    public const SORTS = ['name' => 'name', 'domainCount' => 'domain_count'];

    public function __construct(private readonly DomainScope $scope) {}

    /**
     * @return array{data: list<array{name: string, domainCount: int}>, meta: array{total: int, limit: int, offset: int}}
     */
    public function page(Principal $principal, ListQuery $list): array
    {
        $grouped = $this->scope->restrict(DB::table('tags_domain as t'), $principal, 't.domain')
            ->selectRaw('MIN(`t`.`tag_name` COLLATE utf8mb4_bin) AS `name`, COUNT(DISTINCT `t`.`domain`) AS `domain_count`')
            ->groupByRaw('LOWER(`t`.`tag_name`)');

        if ($list->search !== null) {
            $grouped->where('t.tag_name', 'like', ListQuery::likePattern($list->search));
        }

        $total = DB::query()->fromSub($grouped, 'g')->count();
        $direction = $list->order === 'desc' ? 'DESC' : 'ASC';

        $query = DB::query()->fromSub($grouped, 'g');
        $list->sort === 'domainCount'
            ? $query->orderByRaw("`domain_count` {$direction}, LOWER(`name`) ASC")
            : $query->orderByRaw("LOWER(`name`) {$direction}, `name` {$direction}");

        $rows = $query->offset($list->offset)->limit($list->limit)->get();

        return [
            'data' => array_values($rows->map(fn (object $row): array => ['name' => (string) $row->name, 'domainCount' => (int) $row->domain_count])->all()),
            'meta' => $list->meta($total),
        ];
    }
}
