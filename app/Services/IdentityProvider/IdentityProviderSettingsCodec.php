<?php

namespace App\Services\IdentityProvider;

use App\Auth\IdentityProviderType;
use App\Support\Http\ETag;

/**
 * Maps the rows of upstream `identity_provider` (`key`, `value`) to an IdentityProviderConfig and
 * back (research R9, data-model "Settings, contract members and encodings").
 *
 *  - Reading follows legacy identity_provider('get') and ('init'): FR-007 defaults for missing
 *    keys, PHP truthiness for booleans, JSON lists also as legacy objects, mapping pairs up to the
 *    shorter list, empty texts as null, keys of another type ignored, completeness from the raw
 *    values.
 *  - Writing is byte-identical to legacy identity_provider('edit'): PHP booleans bound as '1' or
 *    '', integers as decimal strings, lists with json_encode()'s default flags, hosts joined by ','
 *    without spaces, and the cached Keycloak service token as ''.
 *  - The version tag covers every row but `access_token`, with the secrets only as an HMAC keyed
 *    with the app key (docs/conventions.md).
 */
class IdentityProviderSettingsCodec
{
    /** The Keycloak service token cache: shared with legacy, never presented, not versioned. */
    public const ACCESS_TOKEN = 'access_token';

    /** Rows whose value is a secret. */
    public const SECRET_KEYS = ['client_secret', 'bindpass'];

    /** Keys of every type, in legacy's order. */
    private const COMMON_KEYS = ['authsource', 'ignore_ssl_error', 'login_provisioning', 'mappers', 'templates', 'default_template'];

    /** Keys of each type besides the common ones, in legacy's order. */
    private const TYPE_KEYS = [
        'keycloak' => ['server_url', 'realm', 'client_id', 'client_secret', 'redirect_url', 'redirect_url_extra', 'version', 'mailpassword_flow', 'periodic_sync', 'import_users', 'sync_interval'],
        'genericOidc' => ['authorize_url', 'token_url', 'userinfo_url', 'client_id', 'client_secret', 'redirect_url', 'redirect_url_extra', 'client_scopes'],
        'ldap' => ['host', 'port', 'basedn', 'username_field', 'filter', 'attribute_field', 'binddn', 'bindpass', 'use_ssl', 'use_tls', 'periodic_sync', 'import_users', 'sync_interval'],
    ];

    /** Contract member of each legacy key (the mapping keys are handled together). */
    private const MEMBER_OF = [
        'ignore_ssl_error' => 'ignoreTlsErrors',
        'login_provisioning' => 'createMailboxOnSignIn',
        'default_template' => 'defaultTemplate',
        'server_url' => 'serverUrl',
        'realm' => 'realm',
        'client_id' => 'clientId',
        'client_secret' => 'clientSecret',
        'redirect_url' => 'legacyReturnUrl',
        'redirect_url_extra' => 'additionalReturnUrls',
        'version' => 'version',
        'mailpassword_flow' => 'mailpasswordFlow',
        'periodic_sync' => 'periodicSync',
        'import_users' => 'importUsers',
        'sync_interval' => 'syncInterval',
        'authorize_url' => 'authorizationEndpoint',
        'token_url' => 'tokenEndpoint',
        'userinfo_url' => 'userInfoEndpoint',
        'client_scopes' => 'scopes',
        'host' => 'hosts',
        'port' => 'port',
        'basedn' => 'baseDn',
        'username_field' => 'usernameField',
        'filter' => 'filter',
        'attribute_field' => 'attributeField',
        'binddn' => 'bindDn',
        'bindpass' => 'bindPassword',
        'use_ssl' => 'useSsl',
        'use_tls' => 'useStartTls',
    ];

    /** Booleans legacy binds as a PHP bool ('1' or ''). */
    private const BOOL_EMPTY = ['ignore_ssl_error', 'login_provisioning', 'use_ssl', 'use_tls'];

    /** Booleans legacy binds as intval() ('1' or '0'). */
    private const BOOL_INT = ['mailpassword_flow', 'periodic_sync', 'import_users'];

    /** JSON lists. */
    private const LISTS = ['redirect_url_extra', 'mappers', 'templates'];

    /**
     * Every key a configuration of the type consists of, the service token cache included.
     *
     * @return list<string>
     */
    public function keysFor(IdentityProviderType $type): array
    {
        return [...self::COMMON_KEYS, ...self::TYPE_KEYS[$type->value], self::ACCESS_TOKEN];
    }

    /**
     * The stored configuration, or null when none is stored (no `authsource` row of a known type).
     *
     * @param  array<string, string>  $rows  key => value
     */
    public function fromRows(array $rows): ?IdentityProviderConfig
    {
        $type = IdentityProviderType::fromLegacy($rows['authsource'] ?? null);

        if ($type === null) {
            return null;
        }

        $members = IdentityProviderConfig::MEMBERS[$type->value];
        $settings = [];

        foreach ($members as $member) {
            $settings[$member] = IdentityProviderConfig::DEFAULTS[$member] ?? null;
        }

        foreach (self::TYPE_KEYS[$type->value] as $key) {
            if (! array_key_exists($key, $rows)) {
                continue;
            }

            $settings[self::MEMBER_OF[$key]] = $this->decode($key, (string) $rows[$key]);
        }

        foreach (['ignore_ssl_error', 'login_provisioning', 'default_template'] as $key) {
            if (array_key_exists($key, $rows)) {
                $settings[self::MEMBER_OF[$key]] = $this->decode($key, (string) $rows[$key]);
            }
        }

        $settings['attributeMapping'] = $this->mapping($rows['mappers'] ?? null, $rows['templates'] ?? null);

        // Legacy never stores StartTLS together with SSL; a hand-edited row shows the effective value.
        if ($type === IdentityProviderType::Ldap && $settings['useSsl'] === true) {
            $settings['useStartTls'] = false;
        }

        return new IdentityProviderConfig($type, $settings, $this->missingInRows($type, $rows));
    }

    /**
     * The rows legacy identity_provider('edit') would store for the configuration, with every key
     * of its type and the service token cleared.
     *
     * @return array<string, string>
     */
    public function toRows(IdentityProviderConfig $config): array
    {
        $s = $config->settings;
        $mapping = (array) ($s['attributeMapping'] ?? []);

        $rows = [
            'authsource' => $config->type->legacy(),
            'ignore_ssl_error' => self::boolEmpty($s['ignoreTlsErrors'] ?? false),
            'login_provisioning' => self::boolEmpty($s['createMailboxOnSignIn'] ?? true),
            'mappers' => self::jsonList(array_column($mapping, 'attributeValue')),
            'templates' => self::jsonList(array_column($mapping, 'template')),
            'default_template' => (string) ($s['defaultTemplate'] ?? ''),
        ];

        $rows += match ($config->type) {
            IdentityProviderType::Keycloak => [
                'server_url' => (string) ($s['serverUrl'] ?? ''),
                'realm' => (string) ($s['realm'] ?? ''),
                'client_id' => (string) ($s['clientId'] ?? ''),
                'client_secret' => (string) ($s['clientSecret'] ?? ''),
                'redirect_url' => (string) ($s['legacyReturnUrl'] ?? ''),
                'redirect_url_extra' => self::jsonList((array) ($s['additionalReturnUrls'] ?? [])),
                'version' => (string) ($s['version'] ?? ''),
                'mailpassword_flow' => self::boolInt($s['mailpasswordFlow'] ?? false),
                'periodic_sync' => self::boolInt($s['periodicSync'] ?? false),
                'import_users' => self::boolInt($s['importUsers'] ?? false),
                'sync_interval' => (string) (int) ($s['syncInterval'] ?? 15),
            ],
            IdentityProviderType::GenericOidc => [
                'authorize_url' => (string) ($s['authorizationEndpoint'] ?? ''),
                'token_url' => (string) ($s['tokenEndpoint'] ?? ''),
                'userinfo_url' => (string) ($s['userInfoEndpoint'] ?? ''),
                'client_id' => (string) ($s['clientId'] ?? ''),
                'client_secret' => (string) ($s['clientSecret'] ?? ''),
                'redirect_url' => (string) ($s['legacyReturnUrl'] ?? ''),
                'redirect_url_extra' => self::jsonList((array) ($s['additionalReturnUrls'] ?? [])),
                'client_scopes' => (string) ($s['scopes'] ?? IdentityProviderConfig::DEFAULT_SCOPES),
            ],
            IdentityProviderType::Ldap => [
                'host' => implode(',', array_map('strval', (array) ($s['hosts'] ?? []))),
                'port' => (string) (int) ($s['port'] ?? 389),
                'basedn' => (string) ($s['baseDn'] ?? ''),
                'username_field' => (string) ($s['usernameField'] ?? 'mail'),
                'filter' => (string) ($s['filter'] ?? ''),
                'attribute_field' => (string) ($s['attributeField'] ?? ''),
                'binddn' => (string) ($s['bindDn'] ?? ''),
                'bindpass' => (string) ($s['bindPassword'] ?? ''),
                'use_ssl' => self::boolEmpty($s['useSsl'] ?? false),
                'use_tls' => self::boolEmpty(($s['useStartTls'] ?? false) && ! ($s['useSsl'] ?? false)),
                'periodic_sync' => self::boolInt($s['periodicSync'] ?? false),
                'import_users' => self::boolInt($s['importUsers'] ?? false),
                'sync_interval' => (string) (int) ($s['syncInterval'] ?? 15),
            ],
        };

        $rows[self::ACCESS_TOKEN] = '';

        return $rows;
    }

    /**
     * The version tag of the stored rows (FR-050).
     *
     * @param  array<string, string>  $rows
     */
    public function etag(array $rows): string
    {
        return ETag::of($this->etagInput($rows));
    }

    /**
     * What the version tag is computed over: every row but the service token, sorted by key, as
     * `key\0value\n`, with each secret replaced by its HMAC keyed with the app key.
     *
     * @param  array<string, string>  $rows
     * @return list<string>
     */
    public function etagInput(array $rows): array
    {
        unset($rows[self::ACCESS_TOKEN]);
        ksort($rows, SORT_STRING);
        $input = [];

        foreach ($rows as $key => $value) {
            $value = in_array($key, self::SECRET_KEYS, true) ? hash_hmac('sha256', (string) $value, (string) config('app.key')) : (string) $value;
            $input[] = $key."\0".$value."\n";
        }

        return $input;
    }

    private function decode(string $key, string $value): mixed
    {
        return match (true) {
            in_array($key, self::BOOL_EMPTY, true), in_array($key, self::BOOL_INT, true) => (bool) $value,
            $key === 'sync_interval' => max(1, (int) $value === 0 ? 15 : (int) $value),
            $key === 'port' => ((int) $value >= 1 && (int) $value <= 65535) ? (int) $value : 389,
            $key === 'host' => array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $h): bool => $h !== '')),
            in_array($key, self::LISTS, true) => self::decodeList($value),
            $key === 'username_field' => $value === '' ? 'mail' : $value,
            $key === 'client_scopes' => $value === '' ? IdentityProviderConfig::DEFAULT_SCOPES : $value,
            default => $value === '' ? null : $value,
        };
    }

    /**
     * Pairs the stored mapping lists up to the shorter one (legacy array_search over both).
     *
     * @return list<array{attributeValue: string, template: string}>
     */
    private function mapping(?string $mappers, ?string $templates): array
    {
        $values = self::decodeList((string) $mappers);
        $names = self::decodeList((string) $templates);
        $entries = [];

        for ($i = 0, $n = min(count($values), count($names)); $i < $n; $i++) {
            $entries[] = ['attributeValue' => $values[$i], 'template' => $names[$i]];
        }

        return $entries;
    }

    /**
     * Legacy completeness: the required values must be truthy in the stored rows.
     *
     * @param  array<string, string>  $rows
     * @return list<string>
     */
    private function missingInRows(IdentityProviderType $type, array $rows): array
    {
        $keyOf = array_flip(self::MEMBER_OF);
        $missing = [];

        foreach (IdentityProviderConfig::REQUIRED_FOR_SIGN_IN[$type->value] as $member) {
            $value = $rows[$keyOf[$member]] ?? '';

            if ($member === 'hosts') {
                $value = str_replace([',', ' '], '', (string) $value);
            }

            if (! (bool) $value) {
                $missing[] = $member;
            }
        }

        return $missing;
    }

    /**
     * A stored JSON list (or a legacy object with numeric keys) as a list of strings, in order.
     *
     * @return list<string>
     */
    private static function decodeList(string $json): array
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_map('strval', array_filter($decoded, 'is_scalar')));
    }

    /**
     * @param  list<mixed>|array<mixed>  $values
     */
    private static function jsonList(array $values): string
    {
        return (string) json_encode(array_values(array_map('strval', $values)));
    }

    private static function boolEmpty(mixed $value): string
    {
        return $value ? '1' : '';
    }

    private static function boolInt(mixed $value): string
    {
        return $value ? '1' : '0';
    }
}
