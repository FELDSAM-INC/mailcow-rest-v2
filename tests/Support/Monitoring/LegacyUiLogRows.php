<?php

namespace Tests\Support\Monitoring;

use Illuminate\Support\Facades\DB;

/**
 * Rows of mailcow's `logs` table written like legacy logger() (feature 011 T048, T049): `msg`
 * encoded with JSON_UNESCAPED_UNICODE and the HEX flags, `call` with JSON_UNESCAPED_UNICODE.
 */
trait LegacyUiLogRows
{
    /**
     * @param  string|list<mixed>|null  $msg
     * @param  list<mixed>|null  $call
     */
    protected function legacyUiLog(string $type, string|array|null $msg, ?array $call, string $user = 'admin', string $role = 'admin', int $time = 1760781600, string $remote = '192.0.2.30', string $task = 'A1B2C3'): int
    {
        return (int) DB::table('logs')->insertGetId([
            'type' => $type,
            'task' => $task,
            'msg' => $msg === null ? null : json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            'call' => $call === null ? null : json_encode($call, JSON_UNESCAPED_UNICODE),
            'user' => $user,
            'role' => $role,
            'remote' => $remote,
            'time' => $time,
        ]);
    }
}
