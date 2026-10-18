<?php

namespace App\Services\MtaSts;

/**
 * The policy text that mailcow's `mta-sts.php` serves for a stored row (feature 010 FR-036,
 * SC-008): `mailbox('get', 'mta_sts')` turns `stsv1` into `STSv1` and splits `mx` on commas, and the
 * endpoint prints `version`, `mode`, `max_age` and one `mx` line per host, each ending in a line
 * feed.
 */
final class MtaStsPolicyText
{
    public static function fromRow(string $version, string $mode, string $maxAge, string $mx): string
    {
        $text = 'version: '.self::version($version)."\n";
        $text .= "mode: {$mode}\n";
        $text .= "max_age: {$maxAge}\n";

        foreach (explode(',', $mx) as $host) {
            $text .= "mx: {$host}\n";
        }

        return $text;
    }

    /** Legacy's display form of the stored version (`stsv1` → `STSv1`). */
    public static function version(string $stored): string
    {
        return strtoupper(substr($stored, 0, 3)).substr($stored, 3);
    }
}
