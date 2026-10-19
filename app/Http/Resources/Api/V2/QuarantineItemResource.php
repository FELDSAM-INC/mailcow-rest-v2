<?php

namespace App\Http\Resources\Api\V2;

use App\Models\Mailcow\QuarantineItem;
use Illuminate\Http\JsonResponse;

/**
 * Contract `QuarantineItem` (feature 006 FR-007): the list representation of an item. The stored
 * message and the quick-action hash are never part of it (FR-015).
 */
final class QuarantineItemResource
{
    /** Stored action => contract action; any other value is `other`. */
    public const ACTIONS = ['reject' => 'rejected', 'add header' => 'junk', 'rewrite subject' => 'subjectRewritten'];

    /**
     * @return array<string, mixed>
     */
    public static function item(QuarantineItem $item): array
    {
        $created = $item->created;
        $action = (string) $item->getRawOriginal('action');
        $score = $item->getRawOriginal('score');

        return [
            'id' => (int) $item->getKey(),
            'queueId' => (string) $item->getRawOriginal('qid'),
            'subject' => mb_scrub((string) $item->getRawOriginal('subject'), 'UTF-8'),
            'sender' => (string) $item->getRawOriginal('sender'),
            'recipient' => (string) $item->getRawOriginal('rcpt'),
            'score' => $score === null ? null : round((float) $score, 2),
            'action' => self::ACTIONS[$action] ?? 'other',
            'filterAction' => $action,
            'virus' => (bool) $item->getRawOriginal('virus'),
            'notified' => (int) $item->getRawOriginal('notified') !== 0,
            'arrivedAt' => $created?->format('Y-m-d\TH:i:s\Z') ?? '1970-01-01T00:00:00Z',
            'etag' => $item->versionTag(),
        ];
    }

    /**
     * @param  list<QuarantineItem>  $items
     * @param  array{total: int, limit: int, offset: int}  $meta
     */
    public static function list(array $items, array $meta): JsonResponse
    {
        return response()->json(['data' => array_map(self::item(...), $items), 'meta' => $meta], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
