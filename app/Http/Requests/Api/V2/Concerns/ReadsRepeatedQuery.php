<?php

namespace App\Http\Requests\Api\V2\Concerns;

/**
 * Every value of a repeatable query parameter (`tag=a&tag=b`), read from the raw query string,
 * because PHP keeps only the last of repeated names without brackets.
 */
trait ReadsRepeatedQuery
{
    /**
     * @return list<string>
     */
    protected function repeatedQuery(string $parameter): array
    {
        $values = [];

        foreach (explode('&', (string) $this->server('QUERY_STRING', '')) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');

            if (urldecode($name) === $parameter) {
                $values[] = urldecode($value);
            }
        }

        return $values;
    }
}
