<?php

namespace App\Services\Logs;

use App\Models\Mailcow\LegacyUiLogEntry;
use App\Support\Http\ListQuery;
use Illuminate\Database\Eloquent\Builder;

/**
 * The legacy UI activity log, table `logs` (feature 011 research R13, FR-028, FR-029): ordered by
 * row ID (insertion order, also time order); `before` is the row ID. Without `q` the page and the
 * total come from SQL. With `q` the search must run on the masked entries, so SQL only pre-filters
 * (the text columns, and `msg`/`call` against the JSON-escaped search as legacy wrote them) and the
 * candidate rows are streamed, masked and matched in PHP; a row that matched only in a masked value
 * is neither returned nor counted.
 */
class LegacyUiActivityLogSource
{
    private const MSG_FLAGS = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

    public function __construct(private readonly SecretMasker $masker) {}

    public function page(LogQuery $query): LogPage
    {
        $rows = $this->filtered($query);

        if ($query->search === null) {
            $total = (clone $rows)->count();
            $page = $rows->orderBy('id', $query->order)->offset($query->offset)->limit($query->limit)->get();

            return new LogPage(array_values($page->map(fn (LegacyUiLogEntry $row): array => $this->entry($row))->all()), $total);
        }

        $this->preFilter($rows, $query->search);
        $matching = [];
        $stream = $query->order === 'asc' ? $rows->lazyById(1000) : $rows->lazyByIdDesc(1000);

        foreach ($stream as $row) {
            $entry = $this->entry($row);

            if (LogMatcher::matches($entry, $query)) {
                $matching[] = $entry;
            }
        }

        return new LogPage(array_slice($matching, $query->offset, $query->limit), count($matching));
    }

    /**
     * @return Builder<LegacyUiLogEntry>
     */
    private function filtered(LogQuery $query): Builder
    {
        $rows = LegacyUiLogEntry::query();

        if ($query->from !== null) {
            $rows->where('time', '>=', $query->from->getTimestamp());
        }

        if ($query->to !== null) {
            $rows->where('time', '<=', $query->to->getTimestamp());
        }

        if ($query->before !== null) {
            $rows->where('id', '<', (int) LogCursor::parseRow($query->before));
        }

        foreach ($query->filters as $field => $values) {
            $column = match ($field) {
                'type' => 'type',
                'user' => 'user',
                default => 'role',
            };
            $rows->where(function (Builder $inner) use ($column, $values): void {
                foreach ($values as $value) {
                    $inner->orWhereRaw('LOWER(`'.$column.'`) LIKE ?', [ListQuery::likePattern(mb_strtolower($value))]);
                }
            });
        }

        return $rows;
    }

    /**
     * @param  Builder<LegacyUiLogEntry>  $rows
     */
    private function preFilter(Builder $rows, string $search): void
    {
        $plain = ListQuery::likePattern(mb_strtolower($search));
        $msg = ListQuery::likePattern(mb_strtolower(substr((string) json_encode($search, self::MSG_FLAGS), 1, -1)));
        $call = ListQuery::likePattern(mb_strtolower(substr((string) json_encode($search, JSON_UNESCAPED_UNICODE), 1, -1)));

        $rows->where(function (Builder $inner) use ($plain, $msg, $call): void {
            foreach (['type', 'task', 'user', 'role', 'remote', 'msg', 'call'] as $column) {
                $inner->orWhereRaw('LOWER(`'.$column.'`) LIKE ?', [$plain]);
            }

            $inner->orWhereRaw('LOWER(`msg`) LIKE ?', [$msg])->orWhereRaw('LOWER(`call`) LIKE ?', [$call]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(LegacyUiLogEntry $row): array
    {
        $msg = self::decode($row->msg);
        $call = self::decode($row->call);

        [$key, $parameters] = match (true) {
            is_string($msg) => [$msg, []],
            is_array($msg) && array_is_list($msg) && $msg !== [] => [is_scalar($msg[0]) ? (string) $msg[0] : null, array_slice($msg, is_scalar($msg[0]) ? 1 : 0)],
            is_array($msg) => [null, [$msg]],
            default => [null, []],
        };

        return [
            'id' => (string) $row->id,
            'time' => gmdate('Y-m-d\TH:i:s\Z', (int) $row->time),
            'type' => (string) $row->type,
            'task' => (string) $row->task,
            'user' => (string) $row->user,
            'role' => (string) $row->role,
            'clientAddress' => (string) $row->remote,
            'messageKey' => $key,
            'messageParameters' => array_values((array) $this->masker->mask($parameters)),
            'call' => is_array($call) && array_is_list($call) ? array_values((array) $this->masker->mask($call)) : [],
        ];
    }

    /** The decoded JSON value; null for no value and for text that is not JSON (fail closed). */
    private static function decode(?string $json): mixed
    {
        if ($json === null || $json === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
}
