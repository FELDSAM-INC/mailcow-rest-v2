<?php

namespace App\Services\IdentityProvider;

use App\Casts\MailcowDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reads the identity provider configuration from upstream `identity_provider` on every call (no
 * caching, FR-059), so changes of the legacy UI and `/api/v1` are visible at once.
 */
class IdentityProviderRepository
{
    public function __construct(private readonly IdentityProviderSettingsCodec $codec) {}

    /**
     * Every stored row, key => value.
     *
     * @return array<string, string>
     */
    public function rows(): array
    {
        return DB::table('identity_provider')->pluck('value', 'key')->map(fn (mixed $v): string => (string) $v)->all();
    }

    /**
     * The stored configuration, or null when none is stored.
     */
    public function current(): ?IdentityProviderConfig
    {
        return $this->codec->fromRows($this->rows());
    }

    /**
     * The stored configuration when it is complete for sign-in (FR-008), else null.
     */
    public function usable(): ?IdentityProviderConfig
    {
        $config = $this->current();

        return $config !== null && $config->isComplete() ? $config : null;
    }

    /**
     * @param  array<string, string>|null  $rows
     */
    public function etag(?array $rows = null): string
    {
        return $this->codec->etag($rows ?? $this->rows());
    }

    /**
     * The last change of a stored setting, the service token excluded (UTC), or null.
     */
    public function modifiedAt(): ?CarbonImmutable
    {
        $value = DB::table('identity_provider')
            ->where('key', '<>', IdentityProviderSettingsCodec::ACCESS_TOKEN)
            ->max(DB::raw('COALESCE(`modified`, `created`)'));

        if ($value === null || $value === '' || str_starts_with((string) $value, '0000-00-00')) {
            return null;
        }

        return CarbonImmutable::parse((string) $value, MailcowDateTime::zone())->utc();
    }

    /**
     * Whether each name is an existing mailbox template (one query).
     *
     * @param  list<string>  $names
     * @return array<string, bool>
     */
    public function templatesExist(array $names): array
    {
        $names = array_values(array_unique($names));

        if ($names === []) {
            return [];
        }

        $found = DB::table('templates')->where('type', 'mailbox')->whereIn('template', $names)->pluck('template')->map(fn (mixed $n): string => (string) $n)->all();
        $result = [];

        foreach ($names as $name) {
            // The column's collation compares case-insensitively; legacy looks templates up with `=`.
            $result[$name] = in_array(mb_strtolower($name), array_map('mb_strtolower', $found), true);
        }

        return $result;
    }
}
