<?php

namespace App\Services\Logs;

/**
 * Turns the `data` of a legacy API request log entry back into a structure (feature 011 research
 * R15). Legacy api_log() wrote each posted field as `name='<json>'`, joined with `", "`, without
 * escaping the quote, so a JSON value may contain `', x='`. Each value is the shortest text up to a
 * `'` followed by `, <name>='` or the end that decodes as JSON; an empty value is null. When the
 * text cannot be split this way the result is null, so that nothing unparsed (and unmasked) is
 * returned.
 */
class LegacyApiDataParser
{
    private const NAME = '[A-Za-z0-9_\-\[\]]+';

    /**
     * @return array<string, mixed>|null
     */
    public function parse(string $data): ?array
    {
        $result = [];
        $offset = 0;
        $length = strlen($data);

        while ($offset < $length) {
            if (preg_match('/\G('.self::NAME.")='/", $data, $m, 0, $offset) !== 1) {
                return null;
            }

            $name = $m[1];
            $start = $offset + strlen($m[0]);
            $found = false;

            for ($end = strpos($data, "'", $start); $end !== false; $end = strpos($data, "'", $end + 1)) {
                $atEnd = $end === $length - 1;

                if (! $atEnd && preg_match('/\G\', '.self::NAME."='/", $data, $unused, 0, $end) !== 1) {
                    continue;
                }

                $raw = substr($data, $start, $end - $start);
                $decoded = json_decode($raw, true);

                if ($raw === '' || json_last_error() === JSON_ERROR_NONE) {
                    $result[$name] = $raw === '' ? null : $decoded;
                    $offset = $atEnd ? $length : $end + 3;
                    $found = true;

                    break;
                }
            }

            if (! $found) {
                return null;
            }
        }

        return $result;
    }
}
