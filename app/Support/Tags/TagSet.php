<?php

namespace App\Support\Tags;

use App\Support\Http\FieldViolations;

/**
 * Tags as a set (feature 002 FR-043/FR-044, feature 003 FR-036, research R20): trimmed, 1 to 255
 * characters, merged without regard to letter case keeping the first spelling, and at most the
 * tagging limit, unless the new set equals the current one or is smaller (a set over the limit,
 * possible through legacy, can only shrink). Generalised from feature 002's DomainTagSet on its
 * second use; domains and mailboxes share it.
 */
final class TagSet
{
    public const MAX_LENGTH = 255;

    /**
     * @param  array<int, mixed>  $tags
     * @param  list<string>  $current
     * @return list<string>
     */
    public static function normalise(array $tags, array $current, int $limit, FieldViolations $violations, string $field = 'tags'): array
    {
        $result = [];
        $seen = [];

        foreach (array_values($tags) as $index => $tag) {
            $tag = trim((string) (is_scalar($tag) ? $tag : ''));

            if ($tag === '') {
                $violations->add("{$field}.{$index}", 'required');

                continue;
            }

            if (mb_strlen($tag) > self::MAX_LENGTH) {
                $violations->add("{$field}.{$index}", 'tooLong', ['max' => self::MAX_LENGTH]);

                continue;
            }

            $key = mb_strtolower($tag);

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $tag;
            }
        }

        if (count($result) > $limit && ! self::shrinksOrEquals($result, $current)) {
            $violations->add($field, 'tooMany', ['max' => $limit]);
        }

        return $result;
    }

    /**
     * Sorted alphabetically without regard to letter case, ties by the original string.
     *
     * @param  list<string>  $tags
     * @return list<string>
     */
    public static function sort(array $tags): array
    {
        usort($tags, fn (string $a, string $b): int => strcmp(mb_strtolower($a), mb_strtolower($b)) ?: strcmp($a, $b));

        return $tags;
    }

    /**
     * Whether two tag sets are equal without regard to letter case.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    public static function equal(array $a, array $b): bool
    {
        $lower = fn (array $tags): array => self::sort(array_values(array_unique(array_map('mb_strtolower', $tags))));

        return $lower($a) === $lower($b);
    }

    /**
     * @param  list<string>  $new
     * @param  list<string>  $current
     */
    private static function shrinksOrEquals(array $new, array $current): bool
    {
        return self::equal($new, $current) || count($new) < count(array_unique(array_map('mb_strtolower', $current)));
    }
}
