<?php

namespace App\Clients\Dns;

/**
 * PHP requests ANY when the additional-records argument is supplied, even if it is null.
 * A failure of that extra query discards an otherwise valid answer to the TXT query.
 * Load only in an isolated test process, so other tests keep the real native function.
 *
 * @param  array<int, mixed>|null  $authoritative
 * @param  array<int, mixed>|null  $additional
 * @return array<int, array<string, mixed>>|false
 */
function dns_get_record(string $name, int $type, ?array &$authoritative = null, ?array &$additional = null, bool $raw = false): array|false
{
    if (func_num_args() >= 4) {
        trigger_error('dns_get_record(): A temporary server error occurred.', E_USER_WARNING);

        return false;
    }

    return [['host' => $name, 'type' => 'TXT', 'entries' => ['v=spf1 ip4:192.0.2.7 -all']]];
}
