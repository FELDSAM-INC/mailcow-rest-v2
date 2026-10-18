<?php

namespace App\Services\Logs;

/**
 * The filters and the search shared by the log sources (feature 011 FR-025): text filters match
 * case-insensitively as substrings, any value of a filter matches, and `q` searches every text of
 * the normalised (masked) entry except its ID, time and severity class.
 */
final class LogMatcher
{
    /**
     * @param  array<string, mixed>  $entry
     */
    public static function matches(array $entry, LogQuery $query): bool
    {
        if ($query->severities !== [] && ! in_array($entry['severity'] ?? null, $query->severities, true)) {
            return false;
        }

        foreach ($query->filters as $field => $values) {
            if (! self::anyContains(is_scalar($entry[$field] ?? null) ? (string) $entry[$field] : '', $values)) {
                return false;
            }
        }

        if ($query->search !== null) {
            $texts = [];
            self::texts(array_diff_key($entry, ['id' => true, 'time' => true, 'severity' => true]), $texts);

            return self::anyContains(implode("\n", $texts), [$query->search]);
        }

        return true;
    }

    /**
     * @param  list<string>  $needles
     */
    public static function anyContains(string $haystack, array $needles): bool
    {
        $haystack = mb_strtolower($haystack);

        foreach ($needles as $needle) {
            if (str_contains($haystack, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $texts
     */
    private static function texts(mixed $value, array &$texts): void
    {
        if (is_array($value)) {
            foreach ($value as $member) {
                self::texts($member, $texts);
            }
        } elseif (is_string($value) || is_int($value) || is_float($value)) {
            $texts[] = (string) $value;
        }
    }
}
