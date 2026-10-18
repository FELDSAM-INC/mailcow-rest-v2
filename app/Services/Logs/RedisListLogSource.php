<?php

namespace App\Services\Logs;

use App\Clients\SharedStore\SharedStore;
use App\Clients\SharedStore\SharedStoreUnavailable;
use Redis;

/**
 * The ten in-memory logs (feature 011 research R12, FR-024 to FR-027, FR-032): the whole Redis list
 * is read (at most `LOG_LINES` + 1 entries; 101 for autodiscover), lines that are not JSON objects
 * with a numeric `time` are dropped, and the entries are sorted by time, newest first, with a
 * stable sort over the list order (equal times keep mailcow's order). `before` keeps the entries
 * after the cursor's entry in that order, or, when it is gone (trimmed or removed), the entries
 * strictly older than its time. Filters and `q` run on the normalised and masked entries; the
 * total counts what remains.
 */
class RedisListLogSource
{
    public function __construct(private readonly SharedStore $store) {}

    /**
     * @throws SharedStoreUnavailable
     */
    public function page(LogQuery $query): LogPage
    {
        $key = (string) $query->type->redisKey();
        $mapper = $query->type->mapper();
        $lines = $this->store->run(fn (Redis $redis): mixed => $redis->lRange($key, 0, -1));
        $lines = is_array($lines) ? array_values(array_filter($lines, 'is_string')) : [];

        // Identical lines are told apart by how many identical older lines precede them.
        $seen = [];
        $entries = [];

        for ($index = count($lines) - 1; $index >= 0; $index--) {
            $raw = $lines[$index];
            $n = $seen[$raw] = ($seen[$raw] ?? -1) + 1;
            $line = json_decode($raw, true);

            if (! is_array($line) || array_is_list($line) || ! is_numeric($line['time'] ?? null) || $mapper === null) {
                continue;
            }

            $entry = $mapper->map($line);

            if ($entry !== null) {
                $time = (int) $line['time'];
                $entries[] = ['index' => $index, 'time' => $time, 'entry' => ['id' => LogCursor::redis($time, $raw, $n)] + $entry];
            }
        }

        usort($entries, fn (array $a, array $b): int => [$b['time'], $a['index']] <=> [$a['time'], $b['index']]);

        if ($query->before !== null) {
            $entries = self::olderThan($entries, $query->before);
        }

        $from = $query->from?->getTimestamp();
        $to = $query->to?->getTimestamp();
        $matching = [];

        foreach ($entries as $item) {
            if (($from === null || $item['time'] >= $from) && ($to === null || $item['time'] <= $to) && LogMatcher::matches($item['entry'], $query)) {
                $matching[] = $item['entry'];
            }
        }

        if ($query->order === 'asc') {
            $matching = array_reverse($matching);
        }

        return new LogPage(array_slice($matching, $query->offset, $query->limit), count($matching));
    }

    /**
     * @param  list<array{index: int, time: int, entry: array<string, mixed>}>  $entries  newest first
     * @return list<array{index: int, time: int, entry: array<string, mixed>}>
     */
    private static function olderThan(array $entries, string $cursor): array
    {
        foreach ($entries as $position => $item) {
            if ($item['entry']['id'] === $cursor) {
                return array_slice($entries, $position + 1);
            }
        }

        $time = LogCursor::parseRedis($cursor)['time'] ?? PHP_INT_MIN;

        return array_values(array_filter($entries, fn (array $item): bool => $item['time'] < $time));
    }
}
