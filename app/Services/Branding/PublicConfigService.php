<?php

namespace App\Services\Branding;

use App\Models\Mailcow\IdentityProviderSetting;
use App\Services\Config\MailcowConfig;
use App\Services\IdentityProvider\IdentityProviderRepository;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The public configuration (FR-042–FR-044, FR-056, R15), read on every request from mailcow's
 * Redis customisation keys (connection `mailcow`), its language settings (MailcowConfig) and the
 * `identity_provider` table, exactly as legacy `customize('get', …)` reads them. Read only: unlike
 * legacy, it never moves `UI_IMPRESS` into `UI_FOOTER`.
 *
 *  - texts that legacy stores HTML-encoded (`TITLE_NAME`, `MAIN_NAME`, `APPS_NAME`) are decoded;
 *    operator HTML (`HELP_TEXT`, `UI_FOOTER`) is passed through;
 *  - a value legacy treats as unset (missing, empty or "0") falls back to its default;
 *  - logos are data URIs of the media types legacy accepts on upload;
 *  - app links are the built-in apps of `$MAILCOW_APPS` (without SOGo ones when SOGo is
 *    disabled), then the operator's `APP_LINKS`, old-style entries converted like legacy.
 */
class PublicConfigService
{
    /** The logo media types legacy accepts on upload, and the type each is served as. */
    public const LOGO_TYPES = [
        'image/gif' => 'image/gif',
        'image/jpeg' => 'image/jpeg',
        'image/pjpeg' => 'image/jpeg',
        'image/png' => 'image/png',
        'image/x-png' => 'image/png',
        'image/svg+xml' => 'image/svg+xml',
    ];

    /** Redis keys of the logo variants. */
    public const LOGO_KEYS = ['light' => 'MAIN_LOGO', 'dark' => 'MAIN_LOGO_DARK'];

    private const EXTERNAL_PROVIDERS = ['keycloak', 'generic-oidc', 'ldap'];

    private const ANNOUNCEMENT_TYPES = ['info', 'warning', 'danger'];

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly MailcowConfig $config,
        private readonly ProductName $productName,
        private readonly IdentityProviderRepository $identityProviders,
    ) {}

    /**
     * Contract `PublicConfig`.
     *
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $title = $this->read('TITLE_NAME');
        $appsLabel = $this->read('APPS_NAME');
        $announcementType = $this->read('UI_ANNOUNCEMENT_TYPE');
        $customLogin = json_decode((string) $this->read('CUSTOM_LOGIN'), true);
        $customLogin = is_array($customLogin) ? $customLogin : [];
        $flag = fn (string $name): bool => is_scalar($customLogin[$name] ?? null) && (int) $customLogin[$name] === 1;

        return [
            'pageTitle' => $title === null ? ProductName::default() : htmlspecialchars_decode($title, ENT_QUOTES),
            'productName' => $this->productName->get(),
            'logos' => [
                'light' => $this->logoRef('light', (string) config('restv2.default_logo_url')),
                'dark' => $this->logoRef('dark', (string) config('restv2.default_logo_dark_url')),
            ],
            'loginHelpHtml' => $this->read('HELP_TEXT'),
            // Legacy moves a non-empty UI_IMPRESS into UI_FOOTER whenever it reads the texts.
            'footerHtml' => $this->read('UI_IMPRESS') ?? $this->read('UI_FOOTER'),
            'announcement' => [
                'text' => $this->read('UI_ANNOUNCEMENT_TEXT'),
                'type' => in_array($announcementType, self::ANNOUNCEMENT_TYPES, true) ? $announcementType : null,
                'active' => $this->read('UI_ANNOUNCEMENT_ACTIVE') === '1',
            ],
            'loginPage' => [
                'hideAdminLogin' => $flag('hide_admin_quicklink'),
                'hideDomainAdminLogin' => $flag('hide_domainadmin_quicklink'),
                'hideMailboxUserLogin' => $flag('hide_user_quicklink'),
                'forceSingleSignOn' => $flag('force_sso'),
            ],
            'languages' => array_map(
                fn (string $code, mixed $name): array => ['code' => $code, 'name' => (string) $name],
                array_map('strval', array_keys($this->config->availableLanguages())),
                array_values($this->config->availableLanguages()),
            ),
            'defaultLanguage' => $this->config->defaultLanguage(),
            'externalIdentityProvider' => [
                'configured' => in_array(IdentityProviderSetting::query()->whereKey('authsource')->value('value'), self::EXTERNAL_PROVIDERS, true),
                // Feature 012 (FR-020): a complete Keycloak or generic OIDC configuration.
                'singleSignOnAvailable' => $this->identityProviders->usable()?->type->offersSingleSignOn() ?? false,
            ],
            'appsLabel' => $appsLabel === null ? null : htmlspecialchars_decode($appsLabel, ENT_QUOTES),
            'appLinks' => [...$this->builtInApps(), ...$this->operatorLinks()],
        ];
    }

    /**
     * The uploaded logo of a variant: its served media type, its bytes and its version (the first
     * 16 hex digits of the SHA-256 of the stored value). Null when none is uploaded or the stored
     * value is not a data URI of an accepted media type.
     *
     * @return array{type: string, bytes: string, version: string}|null
     */
    public function logo(string $variant): ?array
    {
        $value = $this->read(self::LOGO_KEYS[$variant] ?? '');

        if ($value === null || preg_match('#^data:([a-z0-9.+/-]+);base64,(.*)$#is', $value, $m) !== 1) {
            return null;
        }

        $type = self::LOGO_TYPES[strtolower($m[1])] ?? null;
        $bytes = base64_decode(trim($m[2]), true);

        if ($type === null || $bytes === false || $bytes === '') {
            return null;
        }

        return ['type' => $type, 'bytes' => $bytes, 'version' => substr(hash('sha256', $value), 0, 16)];
    }

    /**
     * Contract `Logo`.
     *
     * @return array{url: string, isDefault: bool}
     */
    private function logoRef(string $variant, string $default): array
    {
        $logo = $this->logo($variant);

        return $logo === null
            ? ['url' => $default, 'isDefault' => true]
            : ['url' => "/api/v2/public-config/logos/{$variant}?v={$logo['version']}", 'isDefault' => false];
    }

    /**
     * `$MAILCOW_APPS` as `AppLink`s; with SKIP_SOGO=y, apps whose address points to SOGo are left
     * out (legacy `is_uri('SOGo', …)`).
     *
     * @return list<array<string, mixed>>
     */
    private function builtInApps(): array
    {
        $links = [];

        foreach ($this->config->mailcowApps() as $app) {
            $link = is_scalar($app['link'] ?? null) ? (string) $app['link'] : '';
            $userLink = is_scalar($app['user_link'] ?? null) ? (string) $app['user_link'] : '';

            if (config('restv2.skip_sogo') === true && (stripos($link, 'sogo') !== false || stripos($userLink, 'sogo') !== false)) {
                continue;
            }

            $description = is_scalar($app['description'] ?? null) && (string) $app['description'] !== '' ? (string) $app['description'] : null;
            $links[] = self::appLink((string) ($app['name'] ?? ''), $link, $userLink, (bool) ($app['hide'] ?? false), $description, 'builtIn');
        }

        return $links;
    }

    /**
     * The operator's links (Redis `APP_LINKS`, legacy `customize('get', 'app_links')`): a list of
     * one-key maps `{name: {link, user_link, hide}}`, or old-style `{name: "address"}`.
     *
     * @return list<array<string, mixed>>
     */
    private function operatorLinks(): array
    {
        $raw = $this->read('APP_LINKS');

        if ($raw === null) {
            return [];
        }

        $entries = json_decode($raw, true);

        if (! is_array($entries)) {
            Log::warning('mailcow APP_LINKS is not valid JSON; the operator links are left out.');

            return [];
        }

        $links = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach ($entry as $name => $value) {
                if (is_scalar($value)) {
                    // Old style: the address only.
                    $links[] = self::appLink((string) $name, (string) $value, (string) $value, false, null, 'operator');
                } elseif (is_array($value)) {
                    $links[] = self::appLink(
                        (string) $name,
                        is_scalar($value['link'] ?? null) ? (string) $value['link'] : '',
                        is_scalar($value['user_link'] ?? null) ? (string) $value['user_link'] : '',
                        (bool) ($value['hide'] ?? false),
                        null,
                        'operator',
                    );
                }
            }
        }

        return $links;
    }

    /**
     * @return array{name: string, url: string, signedInUrl: string, description: string|null, showOnLoginPage: bool, source: string}
     */
    private static function appLink(string $name, string $link, string $userLink, bool $hide, ?string $description, string $source): array
    {
        return [
            'name' => $name,
            'url' => $link,
            'signedInUrl' => $userLink !== '' ? $userLink : $link,
            'description' => $description,
            'showOnLoginPage' => ! $hide,
            'source' => $source,
        ];
    }

    /**
     * A mailcow Redis key, or null when legacy treats it as unset (missing, empty or "0") or Redis
     * cannot be read.
     */
    private function read(string $key): ?string
    {
        if ($key === '') {
            return null;
        }

        try {
            $value = $this->redis->connection('mailcow')->get($key);
        } catch (Throwable $e) {
            Log::warning('mailcow Redis could not be read for the public configuration.', ['key' => $key, 'error' => $e->getMessage()]);

            return null;
        }

        return is_string($value) && $value !== '' && $value !== '0' ? $value : null;
    }
}
