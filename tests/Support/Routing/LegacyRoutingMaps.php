<?php

namespace Tests\Support\Routing;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Runs the Postfix and Rspamd lookups of a mailcow release (tests/fixtures/legacy/routing-maps,
 * written by scripts/legacy/extract-routing-maps.sh) against the test database, as the mail server
 * runs them: Postfix substitutes `%s` (the whole key), `%u` (its local part) and `%d` (its domain)
 * with SQL quoting and turns `%%` into `%`; bcc.php binds `:local_dest`.
 */
final class LegacyRoutingMaps
{
    public const RELEASE = '2026-09a';

    /**
     * The values a Postfix map returns for a key (one per result row, NULLs dropped).
     *
     * @return list<string>
     */
    public static function postfix(string $map, string $key): array
    {
        $at = strrpos($key, '@');
        $values = ['%s' => $key, '%u' => $at === false ? $key : substr($key, 0, $at), '%d' => $at === false ? '' : substr($key, $at + 1)];
        $sql = strtr(self::query($map), ['%%' => "\0", ...array_map(fn (string $v): string => substr((string) DB::getPdo()->quote($v), 1, -1), $values)]);
        $sql = rtrim(str_replace("\0", '%', $sql), " ;\n");

        return array_values(array_filter(array_map(
            fn (object $row): ?string => ($value = array_values((array) $row)[0] ?? null) === null ? null : (string) $value,
            DB::select($sql),
        ), fn (?string $v): bool => $v !== null));
    }

    /**
     * The BCC destination bcc.php returns for an address or `@domain`, or null.
     */
    public static function bcc(string $type, string $localDest): ?string
    {
        $row = DB::selectOne(self::query($type === 'sender' ? 'bcc_maps_sender' : 'bcc_maps_recipient'), ['local_dest' => $localDest]);

        return $row === null ? null : (string) $row->bcc_dest;
    }

    private static function query(string $name): string
    {
        $path = dirname(__DIR__, 2).'/fixtures/legacy/routing-maps/'.self::RELEASE.'/'.$name.'.sql';
        $text = @file_get_contents($path);

        if ($text === false) {
            throw new RuntimeException("{$path} is missing; run scripts/legacy/extract-routing-maps.sh.");
        }

        return implode("\n", array_filter(explode("\n", $text), fn (string $line): bool => ! str_starts_with($line, '--')));
    }
}
