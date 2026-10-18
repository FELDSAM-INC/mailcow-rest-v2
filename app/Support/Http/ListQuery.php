<?php

namespace App\Support\Http;

use App\Exceptions\ValidationProblemException;
use App\Http\Requests\Api\V2\ApiFormRequest;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * The shared list parameters (FR-058, R29, docs/conventions.md): `limit` (default 25, 1–1000),
 * `offset` (≥ 0), `sort` and `order` checked against the list's allowed values, and the substring
 * search `q` (at most 255 characters, trimmed, empty means no search). Lists answer
 * `{data, meta: {total, limit, offset}}`.
 */
final class ListQuery
{
    public const DEFAULT_LIMIT = 25;

    public const MAX_LIMIT = 1000;

    /**
     * @param  array<string, string>  $sortColumns  contract sort value => column
     */
    private function __construct(
        public readonly int $limit,
        public readonly int $offset,
        public readonly string $sort,
        public readonly string $order,
        public readonly ?string $search,
        private readonly array $sortColumns,
    ) {}

    /**
     * Validates the list parameters of the request; invalid ones answer `422 validation-failed`.
     *
     * @param  array<string, string>  $sortColumns  contract sort value => column
     */
    public static function fromRequest(Request $request, array $sortColumns, string $defaultSort, string $defaultOrder = 'asc'): self
    {
        $input = [
            'limit' => $request->query('limit'),
            'offset' => $request->query('offset'),
            'sort' => $request->query('sort'),
            'order' => $request->query('order'),
            'q' => $request->query('q'),
        ];

        $validator = Validator::make(array_filter($input, fn (mixed $v): bool => $v !== null), [
            'limit' => ['bail', 'integer', 'between:1,'.self::MAX_LIMIT],
            'offset' => ['bail', 'integer', 'min:0'],
            'sort' => ['bail', 'string', 'in:'.implode(',', array_keys($sortColumns))],
            'order' => ['bail', 'string', 'in:asc,desc'],
            'q' => ['bail', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            throw new ValidationProblemException(ApiFormRequest::violations($validator));
        }

        $search = is_string($input['q']) ? trim($input['q']) : '';

        return new self(
            limit: $input['limit'] === null ? self::DEFAULT_LIMIT : (int) $input['limit'],
            offset: $input['offset'] === null ? 0 : (int) $input['offset'],
            sort: is_string($input['sort']) ? $input['sort'] : $defaultSort,
            order: is_string($input['order']) ? $input['order'] : $defaultOrder,
            search: $search === '' ? null : $search,
            sortColumns: $sortColumns,
        );
    }

    public function sortColumn(): string
    {
        return $this->sortColumns[$this->sort];
    }

    /**
     * A case-insensitive `LIKE` pattern matching the value anywhere, with `%`, `_` and `\`
     * escaped (MariaDB's default escape character is `\`).
     */
    public static function likePattern(string $value): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value).'%';
    }

    /**
     * Restricts the query to rows where any of the columns contains the search term.
     *
     * @template TQuery of Builder|EloquentBuilder<\Illuminate\Database\Eloquent\Model>
     *
     * @param  TQuery  $query
     * @param  list<string>  $columns
     * @return TQuery
     */
    public function applySearch(Builder|EloquentBuilder $query, array $columns): Builder|EloquentBuilder
    {
        if ($this->search === null) {
            return $query;
        }

        $base = $query instanceof EloquentBuilder ? $query->getQuery() : $query;
        $grammar = $base->getGrammar();
        $pattern = self::likePattern(mb_strtolower($this->search));

        $query->where(function ($inner) use ($columns, $grammar, $pattern): void {
            foreach ($columns as $column) {
                $inner->orWhereRaw('LOWER('.$grammar->wrap($column).') LIKE ?', [$pattern]);
            }
        });

        return $query;
    }

    /**
     * Counts, sorts and pages the query and returns the list envelope.
     *
     * @param  Builder|EloquentBuilder<Model>  $query
     * @param  callable(mixed): mixed|null  $map
     * @return array{data: list<mixed>, meta: array{total: int, limit: int, offset: int}}
     */
    public function paginate(Builder|EloquentBuilder $query, ?callable $map = null): array
    {
        $total = (clone $query)->count();
        $rows = $query->orderBy($this->sortColumn(), $this->order)->offset($this->offset)->limit($this->limit)->get();

        return [
            'data' => array_values($map === null ? $rows->all() : $rows->map($map)->all()),
            'meta' => $this->meta($total),
        ];
    }

    /**
     * @return array{total: int, limit: int, offset: int}
     */
    public function meta(int $total): array
    {
        return ['total' => $total, 'limit' => $this->limit, 'offset' => $this->offset];
    }
}
