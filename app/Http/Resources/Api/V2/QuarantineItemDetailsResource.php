<?php

namespace App\Http\Resources\Api\V2;

use App\Models\Mailcow\QuarantineItem;
use App\Services\Quarantine\ParsedMessage;
use Illuminate\Http\JsonResponse;

/**
 * Contract `QuarantineItemDetails` (feature 006 FR-012 to FR-015): the list representation with the
 * spam filter's results, the stored size and the parsed content. `authenticatedSender` only for
 * administrators; `content.attachments` `null` without the right to download attachments.
 */
final class QuarantineItemDetailsResource
{
    public static function response(QuarantineItem $item, string $contentStatus, ?ParsedMessage $content, bool $withAttachments, bool $administrator): JsonResponse
    {
        $data = QuarantineItemResource::item($item) + [
            'sourceAddress' => self::nullable($item->getRawOriginal('ip')),
            'symbols' => self::symbols($item->getRawOriginal('symbols')),
            'fuzzyHashes' => self::strings($item->getRawOriginal('fuzzy_hashes')),
            'size' => (int) $item->getRawOriginal('size'),
            'contentStatus' => $contentStatus,
            'content' => $content?->toArray($withAttachments),
        ];

        if ($administrator) {
            $user = (string) $item->getRawOriginal('user');
            $data['authenticatedSender'] = $user === '' || $user === 'unknown' ? null : $user;
        }

        return response()->json(['data' => $data], 200, ['ETag' => (string) $data['etag']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The stored symbols as `{name, score, options}`: the list the metadata exporter writes, or a
     * map keyed by symbol name.
     *
     * @return list<array{name: string, score: int|float, options: list<string>}>
     */
    private static function symbols(mixed $raw): array
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($decoded)) {
            return [];
        }

        $symbols = [];

        foreach ($decoded as $key => $symbol) {
            if (! is_array($symbol)) {
                continue;
            }

            $name = $symbol['name'] ?? (is_string($key) ? $key : null);
            $score = $symbol['score'] ?? 0;

            if (is_string($name)) {
                $symbols[] = ['name' => $name, 'score' => is_int($score) || is_float($score) ? $score : (is_numeric($score) ? (float) $score : 0), 'options' => self::strings($symbol['options'] ?? [])];
            }
        }

        return $symbols;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $raw): array
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($decoded) ? array_values(array_map(fn (mixed $v): string => is_scalar($v) ? mb_scrub((string) $v, 'UTF-8') : (string) json_encode($v), $decoded)) : [];
    }

    private static function nullable(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
