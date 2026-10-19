<?php

namespace App\Services\Settings;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Services\Audit\AuditLogger;
use App\Services\Branding\ProductName;
use App\Services\Branding\PublicConfigService;
use App\Services\Config\MailcowConfig;
use App\Support\Http\ETag;
use App\Support\Images\ImageInspection;
use App\Support\Images\ImageInspector;
use Redis;

/**
 * The customisation settings of feature 013 (FR-013 to FR-028, FR-035; research R3, R6, R7) in
 * mailcow's Redis keys, exactly as legacy's customize() writes them, so that the legacy UI and
 * feature 001's public configuration show the same result:
 *
 *  - logos: `MAIN_LOGO`, `MAIN_LOGO_DARK` = `data:<detected type>;base64,<data>`;
 *  - UI texts: `TITLE_NAME`, `MAIN_NAME`, `APPS_NAME` HTML-escaped (`htmlspecialchars()` with
 *    PHP's default flags), `HELP_TEXT`, `UI_FOOTER`, `UI_ANNOUNCEMENT_TEXT` as given,
 *    `UI_ANNOUNCEMENT_TYPE` `info`/`warning`/`danger` or empty, `UI_ANNOUNCEMENT_ACTIVE` `1`/`0`;
 *    the footer key of older releases (`UI_IMPRESS`) is read as the footer while it holds a value,
 *    as legacy and the public configuration read it, and moved to `UI_FOOTER` by the next change;
 *  - login page options: `CUSTOM_LOGIN`, JSON with legacy's key order and integer values;
 *  - app links: `APP_LINKS`, a JSON list of one-key maps `{name: {link, user_link, hide}}`, encoded
 *    without flags (escaped slashes and Unicode) like legacy; old-style `{name: address}` entries
 *    are converted on read;
 *  - IP check: `IP_CHECK` `1`/`0`.
 *
 * Every change goes through RedisSettingsStore (version tags over the raw values, one MULTI/EXEC,
 * the audit record in the same transaction). A change that changes nothing writes and audits
 * nothing.
 */
class BrandingService
{
    public const UI_TEXT_KEYS = ['TITLE_NAME', 'MAIN_NAME', 'APPS_NAME', 'HELP_TEXT', 'UI_FOOTER', 'UI_IMPRESS', 'UI_ANNOUNCEMENT_TEXT', 'UI_ANNOUNCEMENT_TYPE', 'UI_ANNOUNCEMENT_ACTIVE'];

    /** UI text members stored HTML-escaped => key. */
    private const ESCAPED = ['pageTitle' => 'TITLE_NAME', 'productName' => 'MAIN_NAME', 'appsHeading' => 'APPS_NAME'];

    private const ANNOUNCEMENT_TYPES = ['info', 'warning', 'danger'];

    /** Login page members => `CUSTOM_LOGIN` keys, in legacy's key order. */
    private const LOGIN_FLAGS = [
        'hideMailboxUserLogin' => 'hide_user_quicklink',
        'hideDomainAdminLogin' => 'hide_domainadmin_quicklink',
        'hideAdminLogin' => 'hide_admin_quicklink',
        'forceSingleSignOn' => 'force_sso',
    ];

    public function __construct(
        private readonly RedisSettingsStore $store,
        private readonly AuditLogger $audit,
        private readonly ImageInspector $images,
        private readonly MailcowConfig $config,
        private readonly PublicConfigService $publicConfig,
    ) {}

    /*
    |----------------------------------------------------------------------
    | Logos (FR-013 to FR-018)
    |----------------------------------------------------------------------
    */

    /**
     * Contract `LogoSetting`.
     *
     * @return array<string, mixed>
     */
    public function logo(string $variant): array
    {
        $key = PublicConfigService::LOGO_KEYS[$variant];

        return $this->presentLogo($variant, $this->store->strings([$key])[$key]);
    }

    /**
     * Stores an inspected logo and returns the new setting.
     *
     * @return array<string, mixed>
     */
    public function replaceLogo(string $variant, string $bytes, ImageInspection $inspection, ?string $ifMatch, Principal $actor): array
    {
        $key = PublicConfigService::LOGO_KEYS[$variant];
        $value = 'data:'.$inspection->mediaType.';base64,'.base64_encode($bytes);

        $this->store->transaction(
            [$key],
            $ifMatch,
            fn (Redis $redis): array => RedisSettingsStore::stringsFrom($redis, [$key]),
            fn (array $raw): string => self::logoTag($variant, $raw[$key]),
            function (array $raw) use ($key, $value, $inspection): ?SettingsWrite {
                if ($raw[$key] === $value) {
                    return null;
                }

                return new SettingsWrite(
                    fn (Redis $multi) => $multi->set($key, $value),
                    fn (Redis $multi) => $raw[$key] === null ? $multi->del($key) : $multi->set($key, $raw[$key]),
                    ['old' => $this->describe($raw[$key])?->toArray(), 'new' => $inspection->toArray()],
                );
            },
            fn (mixed $details) => $this->audit->record(AuditEventType::LogoReplacement, true, $actor, ['type' => 'logo', 'id' => $variant, 'name' => null], null, null, (array) $details),
        );

        return $this->presentLogo($variant, $value);
    }

    /** Deletes a logo; deleting an unset logo changes nothing and is not audited. */
    public function deleteLogo(string $variant, ?string $ifMatch, Principal $actor): void
    {
        $key = PublicConfigService::LOGO_KEYS[$variant];

        $this->store->transaction(
            [$key],
            $ifMatch,
            fn (Redis $redis): array => RedisSettingsStore::stringsFrom($redis, [$key]),
            fn (array $raw): string => self::logoTag($variant, $raw[$key]),
            function (array $raw) use ($key): ?SettingsWrite {
                if ($raw[$key] === null) {
                    return null;
                }

                return new SettingsWrite(
                    fn (Redis $multi) => $multi->del($key),
                    fn (Redis $multi) => $multi->set($key, (string) $raw[$key]),
                    ['old' => $this->describe($raw[$key])?->toArray()],
                );
            },
            fn (mixed $details) => $this->audit->record(AuditEventType::LogoDeletion, true, $actor, ['type' => 'logo', 'id' => $variant, 'name' => null], null, null, (array) $details),
        );
    }

    /**
     * @return array{max_size: int, max_width: int, max_height: int}
     */
    public function logoLimits(): array
    {
        return $this->config->logoLimits();
    }

    private static function logoTag(string $variant, ?string $raw): string
    {
        return ETag::of(['logo', $variant, $raw === null ? null : hash('sha256', $raw)]);
    }

    /**
     * The stored logo's media type (legacy's declared types normalised, an unknown type as null)
     * and bytes, or null when no logo is stored.
     *
     * @return array{type: string|null, bytes: string}|null
     */
    private static function decode(?string $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (preg_match('#^data:([a-z0-9.+/-]+);base64,(.*)$#is', $raw, $m) !== 1) {
            return ['type' => null, 'bytes' => ''];
        }

        $bytes = base64_decode(trim($m[2]), true);

        return ['type' => PublicConfigService::LOGO_TYPES[strtolower($m[1])] ?? null, 'bytes' => $bytes === false ? '' : $bytes];
    }

    private function describe(?string $raw): ?ImageInspection
    {
        $decoded = self::decode($raw);

        return $decoded === null ? null : $this->images->describe($decoded['bytes'], $decoded['type']);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentLogo(string $variant, ?string $raw): array
    {
        $decoded = self::decode($raw);
        $inspection = $decoded === null ? null : $this->images->describe($decoded['bytes'], $decoded['type']);
        $servable = $decoded !== null && $decoded['type'] !== null && $decoded['bytes'] !== '';

        return [
            'variant' => $variant,
            'isSet' => $decoded !== null,
            'mediaType' => $inspection?->mediaType,
            'sizeBytes' => $inspection?->sizeBytes,
            'width' => $inspection?->width,
            'height' => $inspection?->height,
            'url' => $servable ? PublicConfigService::logoUrl($variant, PublicConfigService::logoVersion((string) $raw)) : null,
            'etag' => self::logoTag($variant, $raw),
        ];
    }

    /*
    |----------------------------------------------------------------------
    | UI texts (FR-019 to FR-023)
    |----------------------------------------------------------------------
    */

    /**
     * Contract `UiTexts`.
     *
     * @return array<string, mixed>
     */
    public function uiTexts(): array
    {
        return self::presentUiTexts($this->store->strings(self::UI_TEXT_KEYS));
    }

    /**
     * Applies a validated merge patch of the UI texts (plain text members, `announcement` with
     * `text`, `type`, `active`).
     *
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    public function updateUiTexts(array $patch, ?string $ifMatch, Principal $actor): array
    {
        $this->store->transaction(
            self::UI_TEXT_KEYS,
            $ifMatch,
            fn (Redis $redis): array => RedisSettingsStore::stringsFrom($redis, self::UI_TEXT_KEYS),
            fn (array $raw): string => self::uiTextsTag($raw),
            function (array $raw) use ($patch): ?SettingsWrite {
                $current = self::presentUiTexts($raw);
                $writes = [];
                $changes = [];

                foreach (self::ESCAPED as $member => $key) {
                    if (array_key_exists($member, $patch) && $patch[$member] !== $current[$member]) {
                        $writes[$key] = htmlspecialchars((string) $patch[$member]);
                        $changes[$member] = ['old' => $current[$member], 'new' => $patch[$member]];
                    }
                }

                foreach (['loginHelpHtml' => 'HELP_TEXT', 'footerHtml' => 'UI_FOOTER'] as $member => $key) {
                    if (array_key_exists($member, $patch) && $patch[$member] !== $current[$member]) {
                        $writes[$key] = (string) $patch[$member];
                        $changes[$member] = self::sizes((string) $current[$member], (string) $patch[$member]);
                    }
                }

                $announcement = is_array($patch['announcement'] ?? null) ? $patch['announcement'] : [];

                if (array_key_exists('text', $announcement) && $announcement['text'] !== $current['announcement']['text']) {
                    $writes['UI_ANNOUNCEMENT_TEXT'] = (string) $announcement['text'];
                    $changes['announcement.text'] = self::sizes($current['announcement']['text'], (string) $announcement['text']);
                }

                if (array_key_exists('type', $announcement) && $announcement['type'] !== $current['announcement']['type']) {
                    $writes['UI_ANNOUNCEMENT_TYPE'] = (string) ($announcement['type'] ?? '');
                    $changes['announcement.type'] = ['old' => $current['announcement']['type'], 'new' => $announcement['type']];
                }

                if (array_key_exists('active', $announcement) && $announcement['active'] !== $current['announcement']['active']) {
                    $writes['UI_ANNOUNCEMENT_ACTIVE'] = $announcement['active'] === true ? '1' : '0';
                    $changes['announcement.active'] = ['old' => $current['announcement']['active'], 'new' => $announcement['active']];
                }

                // Legacy moves a non-empty UI_IMPRESS into UI_FOOTER whenever it reads the texts;
                // this API moves it with the next change of the texts (FR-023).
                $migrate = self::filled($raw['UI_IMPRESS']);

                if ($migrate && ! isset($writes['UI_FOOTER'])) {
                    $writes['UI_FOOTER'] = (string) $raw['UI_IMPRESS'];
                }

                if ($writes === [] && ! $migrate) {
                    return null;
                }

                return new SettingsWrite(
                    function (Redis $multi) use ($writes, $migrate): void {
                        foreach ($writes as $key => $value) {
                            $multi->set($key, $value);
                        }

                        if ($migrate) {
                            $multi->del('UI_IMPRESS');
                        }
                    },
                    fn (Redis $multi) => self::restore($multi, $raw),
                    ['changes' => $changes, 'footerMigrated' => $migrate],
                );
            },
            fn (mixed $details) => $this->audit->record(AuditEventType::UiTextsUpdate, true, $actor, ['type' => 'uiTexts', 'id' => null, 'name' => null], null, null, (array) $details),
        );

        return $this->uiTexts();
    }

    /**
     * @param  array<string, string|null>  $raw
     */
    private static function uiTextsTag(array $raw): string
    {
        return ETag::of(['uiTexts', ...array_values($raw)]);
    }

    /**
     * @param  array<string, string|null>  $raw
     * @return array<string, mixed>
     */
    private static function presentUiTexts(array $raw): array
    {
        $plain = fn (?string $value): string => self::filled($value) ? htmlspecialchars_decode((string) $value, ENT_QUOTES) : '';
        $html = fn (?string $value): string => self::filled($value) ? (string) $value : '';
        $type = $raw['UI_ANNOUNCEMENT_TYPE'];

        return [
            'pageTitle' => $plain($raw['TITLE_NAME']),
            'pageTitleDefault' => ProductName::default(),
            'productName' => $plain($raw['MAIN_NAME']),
            'productNameDefault' => ProductName::default(),
            'appsHeading' => $plain($raw['APPS_NAME']),
            'loginHelpHtml' => $html($raw['HELP_TEXT']),
            'footerHtml' => self::filled($raw['UI_IMPRESS']) ? (string) $raw['UI_IMPRESS'] : $html($raw['UI_FOOTER']),
            'announcement' => [
                'text' => $html($raw['UI_ANNOUNCEMENT_TEXT']),
                'type' => in_array($type, self::ANNOUNCEMENT_TYPES, true) ? $type : null,
                'active' => $raw['UI_ANNOUNCEMENT_ACTIVE'] === '1',
            ],
            'etag' => self::uiTextsTag($raw),
        ];
    }

    /** Whether legacy treats a stored text as set (it tests the value for truthiness). */
    private static function filled(?string $value): bool
    {
        return $value !== null && $value !== '' && $value !== '0';
    }

    /**
     * @return array{changed: true, oldBytes: int, newBytes: int}
     */
    private static function sizes(string $old, string $new): array
    {
        return ['changed' => true, 'oldBytes' => strlen($old), 'newBytes' => strlen($new)];
    }

    /*
    |----------------------------------------------------------------------
    | Login page options (FR-024)
    |----------------------------------------------------------------------
    */

    /**
     * Contract `LoginPageSettings`.
     *
     * @return array<string, mixed>
     */
    public function loginPage(): array
    {
        return self::presentLoginPage($this->store->strings(['CUSTOM_LOGIN'])['CUSTOM_LOGIN']);
    }

    /**
     * @param  array<string, bool>  $patch
     * @return array<string, mixed>
     */
    public function updateLoginPage(array $patch, ?string $ifMatch, Principal $actor): array
    {
        $this->store->transaction(
            ['CUSTOM_LOGIN'],
            $ifMatch,
            fn (Redis $redis): array => RedisSettingsStore::stringsFrom($redis, ['CUSTOM_LOGIN']),
            fn (array $raw): string => ETag::of(['loginPage', $raw['CUSTOM_LOGIN']]),
            function (array $raw) use ($patch): ?SettingsWrite {
                $current = self::presentLoginPage($raw['CUSTOM_LOGIN']);
                $changes = [];
                $flags = [];

                foreach (self::LOGIN_FLAGS as $member => $key) {
                    $new = array_key_exists($member, $patch) ? $patch[$member] : $current[$member];
                    $flags[$key] = $new ? 1 : 0;

                    if ($new !== $current[$member]) {
                        $changes[$member] = ['old' => $current[$member], 'new' => $new];
                    }
                }

                if ($changes === []) {
                    return null;
                }

                $value = (string) json_encode($flags);

                return new SettingsWrite(
                    fn (Redis $multi) => $multi->set('CUSTOM_LOGIN', $value),
                    fn (Redis $multi) => self::restore($multi, $raw),
                    $changes,
                );
            },
            fn (mixed $changes) => $this->audit->record(AuditEventType::LoginPageUpdate, true, $actor, ['type' => 'loginPage', 'id' => null, 'name' => null], null, null, ['changes' => $changes]),
        );

        return $this->loginPage();
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentLoginPage(?string $raw): array
    {
        $stored = json_decode((string) $raw, true);
        $stored = is_array($stored) ? $stored : [];
        $options = [];

        foreach (['hideAdminLogin', 'hideDomainAdminLogin', 'hideMailboxUserLogin', 'forceSingleSignOn'] as $member) {
            $value = $stored[self::LOGIN_FLAGS[$member]] ?? null;
            $options[$member] = is_scalar($value) && (int) $value === 1;
        }

        return $options + ['etag' => ETag::of(['loginPage', $raw])];
    }

    /*
    |----------------------------------------------------------------------
    | App links (FR-025 to FR-028)
    |----------------------------------------------------------------------
    */

    /**
     * Contract `AppLinkSettings`.
     *
     * @return array<string, mixed>
     */
    public function appLinks(): array
    {
        return $this->presentAppLinks($this->store->strings(['APP_LINKS'])['APP_LINKS']);
    }

    /**
     * Replaces the custom links (validated `AppLinkSettingsEntry` items, in order).
     *
     * @param  list<array{name: string, url: string, signedInUrl: string|null, showOnLoginPage: bool}>  $links
     * @return array<string, mixed>
     */
    public function updateAppLinks(array $links, ?string $ifMatch, Principal $actor): array
    {
        $this->store->transaction(
            ['APP_LINKS'],
            $ifMatch,
            fn (Redis $redis): array => RedisSettingsStore::stringsFrom($redis, ['APP_LINKS']),
            fn (array $raw): string => ETag::of(['appLinks', $raw['APP_LINKS']]),
            function (array $raw) use ($links): ?SettingsWrite {
                $current = self::customLinks($raw['APP_LINKS']);
                $out = [];

                foreach ($links as $link) {
                    $out[] = [$link['name'] => ['link' => $link['url'], 'user_link' => $link['signedInUrl'] ?? '', 'hide' => ! $link['showOnLoginPage']]];
                }

                // Legacy's json_encode() without flags: escaped slashes and \u escapes, byte-identical.
                $value = (string) json_encode($out);

                if ($value === $raw['APP_LINKS']) {
                    return null;
                }

                return new SettingsWrite(
                    fn (Redis $multi) => $multi->set('APP_LINKS', $value),
                    fn (Redis $multi) => self::restore($multi, $raw),
                    ['links' => ['old' => $current, 'new' => $links]],
                );
            },
            fn (mixed $changes) => $this->audit->record(AuditEventType::AppLinksUpdate, true, $actor, ['type' => 'appLinks', 'id' => null, 'name' => null], null, null, ['changes' => $changes]),
        );

        return $this->appLinks();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAppLinks(?string $raw): array
    {
        return [
            'links' => self::customLinks($raw),
            'builtInLinks' => $this->publicConfig->builtInApps(),
            'etag' => ETag::of(['appLinks', $raw]),
        ];
    }

    /**
     * The stored custom links as `AppLinkSettingsEntry` items; old-style entries converted as legacy
     * converts them.
     *
     * @return list<array{name: string, url: string, signedInUrl: string|null, showOnLoginPage: bool}>
     */
    private static function customLinks(?string $raw): array
    {
        $entries = json_decode((string) $raw, true);
        $links = [];

        foreach (is_array($entries) ? $entries : [] as $entry) {
            foreach (is_array($entry) ? $entry : [] as $name => $value) {
                if (is_scalar($value)) {
                    $links[] = ['name' => (string) $name, 'url' => (string) $value, 'signedInUrl' => (string) $value, 'showOnLoginPage' => true];
                } elseif (is_array($value)) {
                    $userLink = is_scalar($value['user_link'] ?? null) ? (string) $value['user_link'] : '';
                    $links[] = [
                        'name' => (string) $name,
                        'url' => is_scalar($value['link'] ?? null) ? (string) $value['link'] : '',
                        'signedInUrl' => $userLink === '' ? null : $userLink,
                        'showOnLoginPage' => ! (bool) ($value['hide'] ?? false),
                    ];
                }
            }
        }

        return $links;
    }

    /*
    |----------------------------------------------------------------------
    | IP check (FR-035)
    |----------------------------------------------------------------------
    */

    /**
     * Contract `IpCheckSetting`.
     *
     * @return array{enabled: bool, etag: string}
     */
    public function ipCheck(): array
    {
        $raw = $this->store->strings(['IP_CHECK'])['IP_CHECK'];

        return ['enabled' => $raw === '1', 'etag' => ETag::of(['ipCheck', $raw])];
    }

    /**
     * @return array{enabled: bool, etag: string}
     */
    public function updateIpCheck(bool $enabled, ?string $ifMatch, Principal $actor): array
    {
        $this->store->transaction(
            ['IP_CHECK'],
            $ifMatch,
            fn (Redis $redis): array => RedisSettingsStore::stringsFrom($redis, ['IP_CHECK']),
            fn (array $raw): string => ETag::of(['ipCheck', $raw['IP_CHECK']]),
            function (array $raw) use ($enabled): ?SettingsWrite {
                $current = $raw['IP_CHECK'] === '1';

                if ($current === $enabled) {
                    return null;
                }

                return new SettingsWrite(
                    fn (Redis $multi) => $multi->set('IP_CHECK', $enabled ? '1' : '0'),
                    fn (Redis $multi) => self::restore($multi, $raw),
                    ['enabled' => ['old' => $current, 'new' => $enabled]],
                );
            },
            fn (mixed $changes) => $this->audit->record(AuditEventType::IpCheckUpdate, true, $actor, ['type' => 'ipCheck', 'id' => null, 'name' => null], null, null, ['changes' => $changes]),
        );

        return $this->ipCheck();
    }

    /**
     * Restores plain keys to raw values read before a change (null: the key did not exist).
     *
     * @param  array<string, string|null>  $raw
     */
    private static function restore(Redis $multi, array $raw): void
    {
        foreach ($raw as $key => $value) {
            $value === null ? $multi->del($key) : $multi->set($key, $value);
        }
    }
}
