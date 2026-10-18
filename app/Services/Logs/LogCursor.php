<?php

namespace App\Services\Logs;

use App\Support\Http\Base64Url;

/**
 * The opaque entry IDs that clients pass as `before` (feature 011 research R12 to R14):
 *
 *  - in-memory logs: `<time>.<first 16 hex digits of sha256(raw line)>.<n>`, where n counts the
 *    identical older lines, so that the ID survives new entries and trimming;
 *  - the legacy UI activity log: the row ID;
 *  - the login log: base64url of `[datetime, service, real_rip, username]`, the table's ordering
 *    key (it has no row ID and rows are replaced).
 */
final class LogCursor
{
    public static function redis(int $time, string $raw, int $n): string
    {
        return $time.'.'.substr(hash('sha256', $raw), 0, 16).'.'.$n;
    }

    /**
     * @return array{time: int, hash: string, n: int}|null
     */
    public static function parseRedis(string $id): ?array
    {
        if (preg_match('/^(\d{1,12})\.([0-9a-f]{16})\.(\d{1,9})$/', $id, $m) !== 1) {
            return null;
        }

        return ['time' => (int) $m[1], 'hash' => $m[2], 'n' => (int) $m[3]];
    }

    public static function parseRow(string $id): ?int
    {
        return preg_match('/^[1-9]\d{0,18}$/', $id) === 1 ? (int) $id : null;
    }

    public static function login(string $datetime, string $service, string $realRip, string $username): string
    {
        return Base64Url::encode((string) json_encode([$datetime, $service, $realRip, $username], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array{string, string, string, string}|null
     */
    public static function parseLogin(string $id): ?array
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,512}$/', $id) !== 1) {
            return null;
        }

        $decoded = json_decode((string) Base64Url::decode($id), true);

        if (! is_array($decoded) || count($decoded) !== 4 || ! array_is_list($decoded) || array_filter($decoded, 'is_string') !== $decoded
            || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $decoded[0]) !== 1) {
            return null;
        }

        return [$decoded[0], $decoded[1], $decoded[2], $decoded[3]];
    }
}
