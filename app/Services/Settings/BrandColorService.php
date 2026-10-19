<?php

namespace App\Services\Settings;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Services\Audit\AuditLogger;
use App\Support\Color\WcagContrast;
use App\Support\Http\ETag;
use Redis;

/**
 * The brand colours (feature 013 research R5; FR-029 to FR-034), stored by this API only in the
 * Redis hash `restv2:brand-colors` (fields `light` and `dark`, lower-case `#rrggbb`; a cleared
 * colour is a missing field). The hash lives next to mailcow's keys, in the database of the API's
 * `restv2:` keys, and is written through RedisSettingsStore like every other setting of this
 * feature, so the version tag covers the two raw fields. 001's public configuration reads it on
 * every request.
 */
class BrandColorService
{
    /** The full key; on the `restv2` connection it is `brand-colors` behind the connection prefix. */
    public const KEY = 'restv2:brand-colors';

    public function __construct(
        private readonly RedisSettingsStore $store,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Contract `BrandColors`.
     *
     * @return array<string, mixed>
     */
    public function read(): array
    {
        return self::present($this->store->read(fn (Redis $redis): array => self::raw($redis)));
    }

    /**
     * Applies a validated merge patch (`light`, `dark`: a colour or null) and returns the colours.
     *
     * @param  array<string, string|null>  $patch
     * @return array<string, mixed>
     */
    public function update(array $patch, ?string $ifMatch, Principal $actor): array
    {
        $this->store->transaction(
            [self::KEY],
            $ifMatch,
            fn (Redis $redis): array => self::raw($redis),
            fn (array $raw): string => self::etag($raw),
            function (array $raw) use ($patch): ?SettingsWrite {
                $changes = [];

                foreach (['light', 'dark'] as $theme) {
                    if (! array_key_exists($theme, $patch)) {
                        continue;
                    }

                    $new = $patch[$theme] === null ? null : strtolower($patch[$theme]);
                    $old = self::valid($raw[$theme]);

                    if ($new !== $raw[$theme]) {
                        $changes[$theme] = ['old' => $old, 'new' => $new];
                    }
                }

                if ($changes === []) {
                    return null;
                }

                return new SettingsWrite(
                    function (Redis $multi) use ($changes): void {
                        foreach ($changes as $theme => $change) {
                            $change['new'] === null ? $multi->hDel(self::KEY, $theme) : $multi->hSet(self::KEY, $theme, $change['new']);
                        }
                    },
                    function (Redis $multi) use ($raw): void {
                        foreach ($raw as $theme => $value) {
                            $value === null ? $multi->hDel(self::KEY, $theme) : $multi->hSet(self::KEY, $theme, $value);
                        }
                    },
                    $changes,
                );
            },
            fn (mixed $changes) => $this->audit->record(AuditEventType::BrandColorsUpdate, true, $actor, ['type' => 'brandColors', 'id' => null, 'name' => null], null, null, ['changes' => $changes]),
        );

        return $this->read();
    }

    /** A stored colour when it is a valid `#rrggbb`, otherwise null. */
    public static function valid(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#[0-9a-f]{6}$/', $value) === 1 ? $value : null;
    }

    /**
     * @return array{light: string|null, dark: string|null}
     */
    private static function raw(Redis $redis): array
    {
        $raw = RedisSettingsStore::fieldsFrom($redis, self::KEY, ['light', 'dark']);

        return ['light' => $raw['light'], 'dark' => $raw['dark']];
    }

    /**
     * @param  array{light: string|null, dark: string|null}  $raw
     */
    private static function etag(array $raw): string
    {
        return ETag::of(['brandColors', $raw['light'], $raw['dark']]);
    }

    /**
     * @param  array{light: string|null, dark: string|null}  $raw
     * @return array<string, mixed>
     */
    private static function present(array $raw): array
    {
        $light = self::valid($raw['light']);
        $dark = self::valid($raw['dark']);

        return [
            'light' => $light,
            'dark' => $dark,
            'effective' => WcagContrast::effective($light, $dark),
            'etag' => self::etag($raw),
        ];
    }
}
