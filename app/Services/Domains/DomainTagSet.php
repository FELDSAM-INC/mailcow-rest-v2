<?php

namespace App\Services\Domains;

use App\Support\Http\FieldViolations;
use App\Support\Tags\TagSet;

/**
 * A domain's tags as a set (FR-043, FR-044, research D12), the shared App\Support\Tags\TagSet rules
 * (feature 003 generalised them for mailbox tags).
 */
final class DomainTagSet
{
    public const MAX_LENGTH = TagSet::MAX_LENGTH;

    /**
     * @param  array<int, mixed>  $tags
     * @param  list<string>  $current
     * @return list<string>
     */
    public static function normalize(array $tags, array $current, int $limit, FieldViolations $violations, string $field = 'tags'): array
    {
        return TagSet::normalise($tags, $current, $limit, $violations, $field);
    }

    /**
     * @param  list<string>  $tags
     * @return list<string>
     */
    public static function sort(array $tags): array
    {
        return TagSet::sort($tags);
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    public static function equal(array $a, array $b): bool
    {
        return TagSet::equal($a, $b);
    }
}
