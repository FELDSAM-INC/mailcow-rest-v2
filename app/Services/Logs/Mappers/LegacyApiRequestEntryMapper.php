<?php

namespace App\Services\Logs\Mappers;

use App\Services\Logs\LegacyApiDataParser;
use App\Services\Logs\SecretMasker;

/**
 * Requests to the legacy `/api/v1` (json_api.php api_log(): time, uri, method, remote, data). The
 * recorded form data is parsed and masked (FR-028); unparseable data is null.
 */
class LegacyApiRequestEntryMapper implements LogEntryMapper
{
    use MapsLogValues;

    public function __construct(private readonly LegacyApiDataParser $parser, private readonly SecretMasker $masker) {}

    public function map(array $line): ?array
    {
        $data = is_string($line['data'] ?? null) ? $this->parser->parse($line['data']) : null;
        $masked = $data === null ? null : $this->masker->mask($data);

        return [
            'time' => self::time($line['time']),
            'uri' => self::text($line['uri'] ?? null),
            'method' => self::text($line['method'] ?? null),
            'clientAddress' => self::text($line['remote'] ?? null),
            'requestData' => is_array($masked) ? ($masked === [] ? new \stdClass : $masked) : null,
        ];
    }
}
