<?php

namespace App\Services\Logs\Mappers;

/**
 * Messages that hit a rate limit (pipe_rl.php): recipients joined with ", " become a list, and a
 * limit legacy could not identify (`rl_name`/`rl_hash` = `err`) reports null (plan parity note 5).
 * `rateLimitHash` is a bucket identifier that feature 006's resetRateLimitBucket accepts (`RL`
 * followed by letters, digits and `=`, at most 128 characters; API gap G10), or null.
 */
class RateLimitEntryMapper implements LogEntryMapper
{
    use MapsLogValues;

    public function map(array $line): ?array
    {
        $identified = fn (mixed $value): ?string => ($text = self::optionalText($value)) === 'err' ? null : $text;

        return [
            'time' => self::time($line['time']),
            'rateLimitName' => $identified($line['rl_name'] ?? null),
            'rateLimitHash' => self::bucketId($identified($line['rl_hash'] ?? null)),
            'sender' => self::optionalText($line['from'] ?? null),
            'recipients' => array_values(array_filter(array_map('trim', explode(',', self::text($line['rcpt'] ?? null))), fn (string $r): bool => $r !== '')),
            'user' => self::optionalText($line['user'] ?? null),
            'messageId' => self::optionalText($line['message_id'] ?? null),
            'headerFrom' => self::optionalText($line['header_from'] ?? null),
            'subject' => self::optionalText($line['header_subject'] ?? null),
            'scannerQueueId' => self::optionalText($line['qid'] ?? null),
            'clientAddress' => self::optionalText($line['ip'] ?? null),
        ];
    }

    private static function bucketId(?string $hash): ?string
    {
        return $hash !== null && preg_match('/^RL[0-9A-Za-z=]{1,126}$/D', $hash) === 1 ? $hash : null;
    }
}
