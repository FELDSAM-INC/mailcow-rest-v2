<?php

/*
 * Generates `postqueue -j` output with many messages (feature 011 T005, T060), one JSON object per
 * line as Postfix prints it, for the 10,000-message listing limit:
 *
 *   php tests/fixtures/monitoring/make-large-queue.php [count]   (default 12000)
 *
 * Queue IDs are the hexadecimal line number (`A00001` …); queues cycle through deferred, active,
 * hold and incoming. Deterministic, so tests can name the ID of any line.
 */

$count = (int) ($argv[1] ?? 12000);

echo implode("\n", array_map('largeQueueLine', range(1, $count))), "\n";

function largeQueueLine(int $n): string
{
    $queues = ['deferred', 'active', 'hold', 'incoming'];

    return (string) json_encode([
        'queue_name' => $queues[$n % 4],
        'queue_id' => sprintf('A%05X', $n),
        'arrival_time' => 1760700000 + $n,
        'message_size' => 1000 + $n,
        'forced_expire' => false,
        'sender' => sprintf('sender%d@example.org', $n % 50),
        'recipients' => [['address' => sprintf('rcpt%d@partner.example', $n), 'delay_reason' => 'connect to mx.partner.example[192.0.2.10]:25: Connection timed out']],
    ], JSON_UNESCAPED_SLASHES);
}
