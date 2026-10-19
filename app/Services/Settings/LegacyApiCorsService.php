<?php

namespace App\Services\Settings;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Rules\LegacyOrigin;
use App\Services\Audit\AuditLogger;
use App\Support\Http\ETag;
use App\Support\Http\FieldViolations;
use Redis;

/**
 * The CORS settings of the legacy `/api/v1` (feature 013 FR-068 to FR-071, research R21), in
 * legacy's `CORS_SETTINGS` hash: `allowed_origins` and `allowed_methods`, each joined with `", "` as
 * legacy's implode() writes them. They govern only the legacy API; this API sends no CORS headers.
 *
 * Reading: stored origins are split on commas and returned as stored (also bare host names written
 * by older releases); nothing stored means `[]`, that is the server's own origin. Missing methods
 * read as GET, POST, PUT and DELETE (legacy's read adds the typo `OPTION`, which is left out).
 *
 * Writing: the patch is merged into the stored settings and the result is validated as a whole, so
 * a stored invalid origin must be replaced in the same request: every origin `*` or as legacy's
 * valid_origin() accepts it (`invalidFormat`, `format: origin`, by index), at least one method
 * (`tooFew`). Origins are stored in lower case and once each, methods once each, both in the order
 * given; a request that changes neither list writes and audits nothing. The tag covers the two raw
 * fields.
 */
class LegacyApiCorsService
{
    public const KEY = 'CORS_SETTINGS';

    public const METHODS = ['GET', 'POST', 'PUT', 'DELETE'];

    private const FIELDS = ['allowed_origins', 'allowed_methods'];

    public function __construct(
        private readonly RedisSettingsStore $store,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Contract `LegacyApiCorsSettings`.
     *
     * @return array{allowedOrigins: list<string>, allowedMethods: list<string>, serverOrigin: string, etag: string}
     */
    public function read(): array
    {
        return self::present($this->store->read(fn (Redis $redis): array => RedisSettingsStore::fieldsFrom($redis, self::KEY, self::FIELDS)));
    }

    /**
     * Merges and stores a validated `LegacyApiCorsSettingsPatch`.
     *
     * @param  array{allowedOrigins?: list<string>, allowedMethods?: list<string>}  $patch
     * @return array{allowedOrigins: list<string>, allowedMethods: list<string>, serverOrigin: string, etag: string}
     */
    public function update(array $patch, ?string $ifMatch, Principal $actor): array
    {
        $this->store->transaction(
            [self::KEY],
            $ifMatch,
            fn (Redis $redis): array => RedisSettingsStore::fieldsFrom($redis, self::KEY, self::FIELDS),
            fn (array $raw): string => self::tag($raw),
            function (array $raw) use ($patch): ?SettingsWrite {
                $current = self::present($raw);
                $origins = $patch['allowedOrigins'] ?? $current['allowedOrigins'];
                $methods = $patch['allowedMethods'] ?? $current['allowedMethods'];
                self::validate($origins, $methods);

                $origins = array_values(array_unique(array_map(fn (string $o): string => $o === '*' ? $o : strtolower($o), $origins)));
                $methods = array_values(array_unique($methods));
                $changes = [];

                foreach (['allowedOrigins' => $origins, 'allowedMethods' => $methods] as $member => $value) {
                    if ($value !== $current[$member]) {
                        $changes[$member] = ['old' => $current[$member], 'new' => $value];
                    }
                }

                if ($changes === []) {
                    return null;
                }

                $new = ['allowed_origins' => implode(', ', $origins), 'allowed_methods' => implode(', ', $methods)];

                return new SettingsWrite(
                    fn (Redis $multi) => $multi->hMSet(self::KEY, $new),
                    function (Redis $multi) use ($raw): void {
                        foreach ($raw as $field => $value) {
                            $value === null ? $multi->hDel(self::KEY, $field) : $multi->hSet(self::KEY, $field, $value);
                        }
                    },
                    $changes,
                );
            },
            fn (mixed $changes) => $this->audit->record(AuditEventType::LegacyApiCorsUpdate, true, $actor, ['type' => 'legacyApiCors', 'id' => null, 'name' => null], null, null, ['changes' => $changes]),
        );

        return $this->read();
    }

    /**
     * @param  list<string>  $origins
     * @param  list<string>  $methods
     */
    private static function validate(array $origins, array $methods): void
    {
        $violations = new FieldViolations;

        foreach ($origins as $i => $origin) {
            if (! LegacyOrigin::isValid($origin)) {
                $violations->add("allowedOrigins.{$i}", 'invalidFormat', ['format' => 'origin']);
            }
        }

        if ($methods === []) {
            $violations->add('allowedMethods', 'tooFew', ['min' => 1]);
        }

        $violations->throwIfAny();
    }

    /**
     * @param  array<string, string|null>  $raw
     * @return array{allowedOrigins: list<string>, allowedMethods: list<string>, serverOrigin: string, etag: string}
     */
    private static function present(array $raw): array
    {
        $origins = array_values(array_filter(array_map('trim', explode(',', (string) $raw['allowed_origins'])), fn (string $o): bool => $o !== ''));
        $methods = preg_split('/[\s,;]+/', (string) $raw['allowed_methods'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $methods = array_values(array_unique(array_filter($methods, fn (string $m): bool => in_array($m, self::METHODS, true))));

        return [
            'allowedOrigins' => $origins,
            'allowedMethods' => $methods === [] ? self::METHODS : $methods,
            'serverOrigin' => 'https://'.SettingsCapabilitiesService::hostname(),
            'etag' => self::tag($raw),
        ];
    }

    /**
     * @param  array<string, string|null>  $raw
     */
    private static function tag(array $raw): string
    {
        return ETag::of(['legacyApiCors', $raw['allowed_origins'] ?? null, $raw['allowed_methods'] ?? null]);
    }
}
