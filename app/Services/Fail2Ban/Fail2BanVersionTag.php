<?php

namespace App\Services\Fail2Ban;

use App\Support\Http\ETag;

/**
 * Version tags of the ban service settings and rules (feature 011 research R18, R20, FR-057): over
 * the values, never the stored bytes, because the ban service re-stores unchanged settings with
 * Python's `json.dumps` every 10 seconds. The settings include the ban list ID, which works like a
 * secret, so the tag is the shared HMAC keyed with the app key (App\Support\Http\ETag).
 */
final class Fail2BanVersionTag
{
    public static function settings(Fail2BanSettingsValues $values): string
    {
        return ETag::of(self::canonical($values));
    }

    /**
     * @param  list<string>  $rules
     */
    public static function rules(array $rules): string
    {
        return ETag::of(['fail2banRules' => $rules]);
    }

    /**
     * The values in a fixed order, lists sorted and unique.
     *
     * @return array<string, mixed>
     */
    public static function canonical(Fail2BanSettingsValues $values): array
    {
        $list = function (array $entries): array {
            $entries = array_values(array_unique($entries));
            sort($entries, SORT_STRING);

            return $entries;
        };

        return [
            'allowlist' => $list($values->allowlist),
            'banListId' => $values->banListId,
            'banTime' => $values->banTime,
            'banTimeIncrement' => $values->banTimeIncrement,
            'denylist' => $list($values->denylist),
            'externallyEnforced' => $values->externallyEnforced,
            'ipv4PrefixLength' => $values->ipv4PrefixLength,
            'ipv6PrefixLength' => $values->ipv6PrefixLength,
            'maxAttempts' => $values->maxAttempts,
            'maxBanTime' => $values->maxBanTime,
            'retryWindow' => $values->retryWindow,
        ];
    }
}
