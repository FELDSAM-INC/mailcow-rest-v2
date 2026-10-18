<?php

namespace App\Services\Logs\Mappers;

use App\Services\Logs\Base64Message;

/** Certificate renewal (acme functions.sh: time, message, optionally `base64,`-prefixed). */
class AcmeEntryMapper implements LogEntryMapper
{
    use MapsLogValues;

    public function map(array $line): ?array
    {
        return [
            'time' => self::time($line['time']),
            'message' => Base64Message::decode(self::text($line['message'] ?? null)),
        ];
    }
}
