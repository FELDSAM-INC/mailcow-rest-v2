<?php

namespace App\Services\Settings;

use Redis;

/**
 * Plans a patch of plain Redis strings for RedisSettingsStore. Missing and empty values compare
 * equally, while compensation preserves their original distinction. Each resource supplies its
 * member-to-key map and the template members whose audit data must contain byte counts only.
 */
final class StringSettingsPatch
{
    /**
     * @param  array<string, string|null>  $raw  the watched keys and their stored values
     * @param  array<string, string>  $patch  validated members
     * @param  array<string, string>  $members  member => Redis key
     * @param  list<string>  $templateMembers
     */
    public static function plan(array $raw, array $patch, array $members, array $templateMembers): ?SettingsWrite
    {
        $writes = [];
        $changes = [];

        foreach ($members as $member => $key) {
            $old = (string) ($raw[$key] ?? '');

            if (! array_key_exists($member, $patch) || $patch[$member] === $old) {
                continue;
            }

            $writes[$key] = $patch[$member];
            $changes[$member] = in_array($member, $templateMembers, true)
                ? ['changed' => true, 'oldBytes' => strlen($old), 'newBytes' => strlen($patch[$member])]
                : ['old' => $old, 'new' => $patch[$member]];
        }

        if ($writes === []) {
            return null;
        }

        return new SettingsWrite(
            function (Redis $multi) use ($writes): void {
                foreach ($writes as $key => $value) {
                    $multi->set($key, $value);
                }
            },
            function (Redis $multi) use ($raw): void {
                foreach ($raw as $key => $value) {
                    $value === null ? $multi->del($key) : $multi->set($key, $value);
                }
            },
            $changes,
        );
    }
}
