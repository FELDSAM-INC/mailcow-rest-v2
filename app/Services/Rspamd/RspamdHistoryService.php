<?php

namespace App\Services\Rspamd;

use App\Clients\Rspamd\RspamdController;
use App\Clients\Rspamd\RspamdUnavailable;
use App\Exceptions\ProblemException;
use App\Support\Http\ListQuery;
use App\Support\Http\ProblemType;
use Carbon\CarbonImmutable;

/**
 * Rspamd's scan history (feature 011 research R16, FR-033, FR-034, legacy
 * get_logs('rspamd-history')): read from the controller at request time (at most 1,000 rows, not
 * cached), mapped to the contract with the symbols in the legacy dashboard's order (positive scores
 * from the largest, then negative from the most negative, then zero; ties by name), filtered,
 * searched, sorted (ties keep Rspamd's order, newest first) and paged in PHP.
 */
class RspamdHistoryService
{
    private const SORTS = ['time' => 'time', 'score' => 'score', 'size' => 'sizeBytes', 'scanTime' => 'scanTimeRealSeconds'];

    public function __construct(private readonly RspamdController $rspamd) {}

    /**
     * @param  list<string>  $actions
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}
     */
    public function list(ListQuery $list, array $actions, ?float $minScore, ?float $maxScore, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        try {
            $history = $this->rspamd->history();
        } catch (RspamdUnavailable) {
            throw self::unavailable();
        }

        $actions = array_map('mb_strtolower', $actions);
        $search = $list->search === null ? null : mb_strtolower($list->search);
        $entries = [];

        foreach (is_array($history['rows'] ?? null) ? $history['rows'] : [] as $position => $row) {
            if (! is_array($row)) {
                continue;
            }

            $entry = self::entry($row);
            $time = (int) ($row['unix_time'] ?? 0);

            if (($actions === [] || in_array(mb_strtolower($entry['action']), $actions, true))
                && ($minScore === null || $entry['score'] >= $minScore)
                && ($maxScore === null || $entry['score'] <= $maxScore)
                && ($from === null || $time >= $from->getTimestamp())
                && ($to === null || $time <= $to->getTimestamp())
                && ($search === null || self::matches($entry, $search))) {
                $entries[] = ['position' => (int) $position, 'unix' => $time, 'entry' => $entry];
            }
        }

        $field = self::SORTS[$list->sort];
        usort($entries, function (array $a, array $b) use ($field, $list): int {
            $order = $field === 'time' ? $a['unix'] <=> $b['unix'] : $a['entry'][$field] <=> $b['entry'][$field];
            $order = $list->order === 'desc' ? -$order : $order;

            // Ties keep Rspamd's order (newest first).
            return $order ?: $a['position'] <=> $b['position'];
        });

        return [
            'data' => array_column(array_slice($entries, $list->offset, $list->limit), 'entry'),
            'meta' => $list->meta(count($entries)),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function entry(array $row): array
    {
        $text = fn (mixed $value): ?string => is_scalar($value) && (string) $value !== '' ? (string) $value : null;
        $list = fn (mixed $value): array => is_array($value) ? array_values(array_map('strval', array_filter($value, 'is_scalar'))) : [];
        $user = $text($row['user'] ?? null);

        return [
            'time' => gmdate('Y-m-d\TH:i:s\Z', (int) ($row['unix_time'] ?? 0)),
            'clientAddress' => $text($row['ip'] ?? null),
            'envelopeSender' => $text($row['sender_smtp'] ?? null),
            'headerSender' => $text($row['sender_mime'] ?? null),
            'envelopeRecipients' => $list($row['rcpt_smtp'] ?? null),
            'headerRecipients' => $list($row['rcpt_mime'] ?? null),
            'subject' => $text($row['subject'] ?? null),
            'action' => (string) ($text($row['action'] ?? null) ?? ''),
            'score' => (float) ($row['score'] ?? 0),
            'requiredScore' => (float) ($row['required_score'] ?? 0),
            'symbols' => self::symbols(is_array($row['symbols'] ?? null) ? $row['symbols'] : []),
            'sizeBytes' => max(0, (int) ($row['size'] ?? 0)),
            'scanTimeRealSeconds' => max(0.0, (float) ($row['time_real'] ?? 0)),
            'scanTimeVirtualSeconds' => is_numeric($row['time_virtual'] ?? null) ? max(0.0, (float) $row['time_virtual']) : null,
            'messageId' => $text($row['message-id'] ?? null),
            // Rspamd records "unknown" when no user authenticated.
            'user' => $user === null || $user === 'unknown' ? null : $user,
        ];
    }

    /**
     * @param  array<mixed>  $symbols  name => {score, options}
     * @return list<array{name: string, score: float, options: list<string>}>
     */
    private static function symbols(array $symbols): array
    {
        $list = [];

        foreach ($symbols as $name => $symbol) {
            if (! is_array($symbol)) {
                continue;
            }

            $list[] = [
                'name' => is_string($symbol['name'] ?? null) ? $symbol['name'] : (string) $name,
                'score' => (float) ($symbol['score'] ?? 0),
                'options' => is_array($symbol['options'] ?? null) ? array_values(array_map('strval', array_filter($symbol['options'], 'is_scalar'))) : [],
            ];
        }

        $class = fn (float $score): int => $score > 0 ? 0 : ($score < 0 ? 1 : 2);
        usort($list, fn (array $a, array $b): int => [$class($a['score']), $a['score'] > 0 ? -$a['score'] : $a['score'], $a['name']]
            <=> [$class($b['score']), $b['score'] > 0 ? -$b['score'] : $b['score'], $b['name']]);

        return $list;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function matches(array $entry, string $search): bool
    {
        $texts = [$entry['envelopeSender'], $entry['headerSender'], ...$entry['envelopeRecipients'], ...$entry['headerRecipients'], $entry['subject'], $entry['clientAddress'], $entry['user'], $entry['messageId']];

        foreach ($texts as $text) {
            if ($text !== null && str_contains(mb_strtolower((string) $text), $search)) {
                return true;
            }
        }

        return false;
    }

    public static function unavailable(): ProblemException
    {
        return new ProblemException(ProblemType::TemporaryFailure, 'The Rspamd controller is unavailable; retry later.', ['dependency' => 'spamFilter'], ['Retry-After' => '5']);
    }
}
