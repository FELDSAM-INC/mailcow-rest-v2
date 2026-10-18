<?php

namespace App\Support\Http;

use Illuminate\Http\Request;

/**
 * Version tags (constitution II, docs/conventions.md): a strong ETag over a resource's stored
 * settings. The tag is an HMAC-SHA-256 keyed with the app key, base64url, quoted, so that it never
 * exposes a plain hash of a stored value. The same stored values give the same tag in every
 * feature.
 */
final class ETag
{
    /**
     * The tag of the given stored values (order matters).
     *
     * @param  array<int|string, mixed>  $values
     */
    public static function of(array $values): string
    {
        $canonical = json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return '"'.Base64Url::encode(hash_hmac('sha256', $canonical, (string) config('app.key'), true)).'"';
    }

    /**
     * Whether an `If-Match` precondition fails: the header is present, is not `*`, and lists no
     * tag that strongly equals the current one (weak tags never match). An absent header passes.
     */
    public static function ifMatchFails(Request $request, string $current): bool
    {
        return self::preconditionFails($request->headers->get('If-Match'), $current);
    }

    /**
     * ifMatchFails() for an `If-Match` value given directly, for example a bulk item's version.
     */
    public static function preconditionFails(?string $header, string $current): bool
    {
        if ($header === null || trim($header) === '') {
            return false;
        }

        foreach (self::parse($header) as $tag) {
            if ($tag === '*' || $tag === $current) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether `If-None-Match` lists the current tag (weak comparison) or `*`, so that a
     * conditional GET can answer 304.
     */
    public static function ifNoneMatchMatches(Request $request, string $current): bool
    {
        $header = $request->headers->get('If-None-Match');

        if ($header === null) {
            return false;
        }

        $strip = fn (string $tag): string => str_starts_with($tag, 'W/') ? substr($tag, 2) : $tag;

        foreach (self::parse($header) as $tag) {
            if ($tag === '*' || $strip($tag) === $strip($current)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function parse(string $header): array
    {
        preg_match_all('/\*|(?:W\/)?"[^"]*"/', $header, $matches);

        return $matches[0];
    }
}
