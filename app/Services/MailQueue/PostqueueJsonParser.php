<?php

namespace App\Services\MailQueue;

/**
 * `postqueue -j` output, one JSON object per line (feature 011 research R17, R33, legacy
 * mailq('get')): the first 10,000 lines are decoded into messages, as legacy does; from the lines
 * beyond, only the queue ID and the queue are taken, for the counts and for the per-ID results of
 * the actions. Empty lines, the line `1`, unparseable lines and unknown queues are skipped.
 */
class PostqueueJsonParser
{
    public function parse(string $output, int $max = 10000): MailQueueListing
    {
        $messages = [];
        $ids = [];
        $counts = array_fill_keys(MailQueueListing::QUEUES, 0);
        $lines = 0;

        foreach (preg_split('/\r?\n|\r/', $output) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || $line === '1') {
                continue;
            }

            $lines++;

            if ($lines <= $max) {
                $message = self::message(json_decode($line, true));

                if ($message === null) {
                    continue;
                }

                $messages[] = $message;
                [$id, $queue] = [$message['id'], $message['queue']];
            } else {
                if (preg_match('/"queue_id"\s*:\s*"([0-9A-Fa-f]{1,32})"/', $line, $idMatch) !== 1
                    || preg_match('/"queue_name"\s*:\s*"([a-z]+)"/', $line, $queueMatch) !== 1
                    || ! in_array($queueMatch[1], MailQueueListing::QUEUES, true)) {
                    continue;
                }

                [$id, $queue] = [$idMatch[1], $queueMatch[1]];
            }

            $ids[$id] = true;
            $counts[$queue]++;
        }

        $counts['total'] = array_sum($counts);

        return new MailQueueListing($messages, $counts, $lines > $max, $ids);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function message(mixed $line): ?array
    {
        if (! is_array($line) || ! is_string($line['queue_id'] ?? null) || preg_match('/^[0-9A-Fa-f]{1,32}$/', $line['queue_id']) !== 1
            || ! in_array($line['queue_name'] ?? null, MailQueueListing::QUEUES, true)) {
            return null;
        }

        $recipients = [];

        foreach (is_array($line['recipients'] ?? null) ? $line['recipients'] : [] as $recipient) {
            if (is_array($recipient) && is_string($recipient['address'] ?? null)) {
                $recipients[] = [
                    'address' => $recipient['address'],
                    'deferralReason' => is_string($recipient['delay_reason'] ?? null) ? $recipient['delay_reason'] : null,
                ];
            }
        }

        return [
            'id' => $line['queue_id'],
            'queue' => $line['queue_name'],
            'arrivalTime' => gmdate('Y-m-d\TH:i:s\Z', (int) ($line['arrival_time'] ?? 0)),
            'sizeBytes' => max(0, (int) ($line['message_size'] ?? 0)),
            'sender' => is_string($line['sender'] ?? null) ? $line['sender'] : '',
            'recipients' => $recipients,
            'forcedExpiry' => is_bool($line['forced_expire'] ?? null) ? $line['forced_expire'] : null,
        ];
    }
}
