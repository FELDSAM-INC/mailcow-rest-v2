<?php

namespace App\Http\Requests\Api\V2;

use App\Rules\Fail2BanAllowlistEntry;
use App\Rules\Fail2BanDenylistEntry;

/**
 * Body of `updateFail2BanSettings` (feature 011 FR-045 to FR-047, `Fail2BanSettingsPatch`): a JSON
 * Merge Patch with at least one member; numbers are integers and flags booleans (`null` is
 * `invalidType`), lists hold at most 10,000 entries of at most 253 characters, each a valid
 * allowlist or denylist entry. The ranges and the rules that need the stored values are the
 * settings service's. Entries are trimmed and stored once.
 */
class UpdateFail2BanSettingsRequest extends ApiFormRequest
{
    protected bool $requiresNonEmptyBody = true;

    private const NUMBERS = ['banTime', 'maxBanTime', 'maxAttempts', 'retryWindow', 'ipv4PrefixLength', 'ipv6PrefixLength'];

    private const FLAGS = ['banTimeIncrement', 'externallyEnforced'];

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $max = (int) config('restv2.monitoring.ban_list_max_entries', 10000);
        $length = (int) config('restv2.monitoring.ban_list_entry_max_length', 253);
        $rules = [];

        foreach (self::NUMBERS as $name) {
            $rules[$name] = ['bail', 'integer:strict'];
        }

        foreach (self::FLAGS as $name) {
            $rules[$name] = ['bail', 'boolean:strict'];
        }

        foreach (['allowlist' => new Fail2BanAllowlistEntry, 'denylist' => new Fail2BanDenylistEntry] as $list => $entry) {
            $rules[$list] = ['bail', 'array', 'list', "max:{$max}"];
            $rules["{$list}.*"] = ['bail', 'required', 'string', "max:{$length}", $entry];
        }

        return $rules;
    }

    /**
     * The validated patch in contract names; lists trimmed and unique.
     *
     * @return array<string, mixed>
     */
    public function patch(): array
    {
        $patch = [];

        foreach ([...self::NUMBERS, ...self::FLAGS, 'allowlist', 'denylist'] as $name) {
            if ($this->has($name)) {
                $value = $this->input($name);
                $patch[$name] = is_array($value) ? array_values(array_unique(array_map(fn (mixed $e): string => trim((string) $e), $value))) : $value;
            }
        }

        return $patch;
    }
}
