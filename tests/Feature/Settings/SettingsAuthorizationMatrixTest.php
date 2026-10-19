<?php

namespace Tests\Feature\Settings;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Settings\SettingsTestHelpers;
use Tests\Support\Settings\UsesSharedFiles;
use Tests\TestCase;

/**
 * Feature 013 T032, T044, T051, T060, T063, T079, T088 (FR-001 to FR-006, SC-010; constitution VI,
 * quality gate 5): every operation of the system settings for every kind of caller, derived from
 * FR-005 and 001's request pipeline: authentication, the key scope (read-only keys may only read,
 * except the recipient preview), the session-only operations, pending actions, then the role. Every
 * refusal of a state-changing operation by an authenticated caller writes exactly one failure audit
 * record and changes nothing; anonymous requests, reads and the preview write none.
 */
class SettingsAuthorizationMatrixTest extends TestCase
{
    use SettingsTestHelpers;
    use UsesSharedFiles;

    private const CALLERS = [
        'anonymous',
        'restrictedSession',
        'adminSession',
        'adminKey',
        'adminReadOnlyKey',
        'domainAdminSession',
        'domainAdminKey',
        'domainAdminReadOnlyKey',
        'mailboxSession',
    ];

    /** Who may use an operation: `ok` or `forbidden` per role. */
    private const ADMIN_ONLY = ['admin' => 'ok', 'domainAdmin' => 'forbidden', 'mailbox' => 'forbidden'];

    private const ADMIN_AND_DOMAIN_ADMIN = ['admin' => 'ok', 'domainAdmin' => 'ok', 'mailbox' => 'forbidden'];

    /** Request bodies that are raw files rather than JSON. */
    private const PNG = '@png';

    /** @var array<string, string> */
    private array $keys = [];

    /** @var array<string, string> */
    private array $values = [];

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->admin('admin', 'Passw0rd!', 'SSHA256');
        $f->admin('restricted', 'Passw0rd!', 'SSHA256', attributes: ['force_pw_update' => '1']);
        $f->domainAdmin('reseller', ['example.org'], scheme: 'SSHA256');
        $f->mailbox('alice@example.org', 'Passw0rd!', 'SSHA256');
        $this->keys = [
            'adminKey' => $this->apiKey('admin')['secret'],
            'adminReadOnlyKey' => $this->apiKey('admin', 'readOnly')['secret'],
            'domainAdminKey' => $this->apiKey('reseller')['secret'],
            'domainAdminReadOnlyKey' => $this->apiKey('reseller', 'readOnly')['secret'],
        ];
        $this->sharedValue('MAIN_LOGO', 'data:image/png;base64,'.base64_encode((string) file_get_contents(__DIR__.'/../../fixtures/logos/valid/logo-300x80.png')));
        $this->values = [
            '{rule}' => (string) $f->settingsRule('Rule', 'x = 1;', 0),
        ];
    }

    /**
     * operation => [method, path, body (null: none), success status, rule per role, options]. The
     * options are `session` (keys refused with `session-required`) and `readOnly` (read-only keys
     * may use the POST).
     *
     * @return array<string, array{string, string, array<string, mixed>|string|null, int, array<string, string>, list<string>}>
     */
    private static function requests(): array
    {
        return [
            // US1: branding and the settings capabilities.
            'getSettingsCapabilities' => ['GET', '/api/v2/capabilities/settings', null, 200, self::ADMIN_ONLY, []],
            'getLogoSetting' => ['GET', '/api/v2/settings/logos/light', null, 200, self::ADMIN_ONLY, []],
            'replaceLogo' => ['PUT', '/api/v2/settings/logos/dark', self::PNG, 200, self::ADMIN_ONLY, []],
            'deleteLogo' => ['DELETE', '/api/v2/settings/logos/light', null, 204, self::ADMIN_ONLY, []],
            'getUiTexts' => ['GET', '/api/v2/settings/ui-texts', null, 200, self::ADMIN_ONLY, []],
            'updateUiTexts' => ['PATCH', '/api/v2/settings/ui-texts', ['productName' => 'Acme'], 200, self::ADMIN_ONLY, []],
            'getLoginPageSettings' => ['GET', '/api/v2/settings/login-page', null, 200, self::ADMIN_ONLY, []],
            'updateLoginPageSettings' => ['PATCH', '/api/v2/settings/login-page', ['hideAdminLogin' => true], 200, self::ADMIN_ONLY, []],
            'getAppLinkSettings' => ['GET', '/api/v2/settings/app-links', null, 200, self::ADMIN_ONLY, []],
            'updateAppLinkSettings' => ['PATCH', '/api/v2/settings/app-links', ['links' => [['name' => 'Wiki', 'url' => '/wiki', 'signedInUrl' => null, 'showOnLoginPage' => true]]], 200, self::ADMIN_ONLY, []],
            'getBrandColors' => ['GET', '/api/v2/settings/brand-colors', null, 200, self::ADMIN_ONLY, []],
            'updateBrandColors' => ['PATCH', '/api/v2/settings/brand-colors', ['light' => '#1d4ed8'], 200, self::ADMIN_ONLY, []],
            // US2: the password policy change (003's read stays open to domain administrators) and
            // the password reset notification.
            'updatePasswordPolicy' => ['PATCH', '/api/v2/password-policy', ['minLength' => 8], 200, self::ADMIN_ONLY, []],
            'getPasswordResetNotification' => ['GET', '/api/v2/settings/password-reset-notification', null, 200, self::ADMIN_ONLY, []],
            'updatePasswordResetNotification' => ['PATCH', '/api/v2/settings/password-reset-notification', ['subject' => 'Reset'], 200, self::ADMIN_ONLY, []],
            // US3: Rspamd settings rules, regex maps, the effective settings map and the presets.
            'listRspamdSettingsRules' => ['GET', '/api/v2/settings/rspamd-rules', null, 200, self::ADMIN_ONLY, []],
            'createRspamdSettingsRule' => ['POST', '/api/v2/settings/rspamd-rules', ['description' => 'New', 'content' => 'priority = 1;'], 201, self::ADMIN_ONLY, []],
            'getRspamdSettingsRule' => ['GET', '/api/v2/settings/rspamd-rules/{rule}', null, 200, self::ADMIN_ONLY, []],
            'updateRspamdSettingsRule' => ['PATCH', '/api/v2/settings/rspamd-rules/{rule}', ['active' => true], 200, self::ADMIN_ONLY, []],
            'deleteRspamdSettingsRule' => ['DELETE', '/api/v2/settings/rspamd-rules/{rule}', null, 204, self::ADMIN_ONLY, []],
            'listRspamdRegexMaps' => ['GET', '/api/v2/settings/rspamd-regex-maps', null, 200, self::ADMIN_ONLY, []],
            'getRspamdRegexMap' => ['GET', '/api/v2/settings/rspamd-regex-maps/bad_words.map', null, 200, self::ADMIN_ONLY, []],
            'replaceRspamdRegexMap' => ['PUT', '/api/v2/settings/rspamd-regex-maps/bad_words.map', ['content' => '/x/i'], 200, self::ADMIN_ONLY, []],
            'getEffectiveRspamdSettingsMap' => ['GET', '/api/v2/settings/rspamd-settings-map', null, 200, self::ADMIN_ONLY, []],
            'listPresets' => ['GET', '/api/v2/presets/sieve', null, 200, self::ADMIN_AND_DOMAIN_ADMIN, []],
            // US4: the global Sieve filters.
            'listGlobalSieveFilters' => ['GET', '/api/v2/settings/global-sieve-filters', null, 200, self::ADMIN_ONLY, []],
            'getGlobalSieveFilter' => ['GET', '/api/v2/settings/global-sieve-filters/prefilter', null, 200, self::ADMIN_ONLY, []],
            'replaceGlobalSieveFilter' => ['PUT', '/api/v2/settings/global-sieve-filters/postfilter', ['script' => "keep;\n", 'deferRestart' => true], 200, self::ADMIN_ONLY, []],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        $cases = [];

        foreach (array_keys(self::requests()) as $operation) {
            foreach (self::CALLERS as $caller) {
                $cases["{$operation} as {$caller}"] = [$operation, $caller];
            }
        }

        return $cases;
    }

    private static function role(string $caller): string
    {
        return match (true) {
            str_starts_with($caller, 'domainAdmin') => 'domainAdmin',
            str_starts_with($caller, 'mailbox') => 'mailbox',
            default => 'admin',
        };
    }

    /**
     * @return array{int, string|null} status and problem type name (null: none)
     */
    private static function expected(string $operation, string $caller): array
    {
        [$method, , , $success, $rules, $options] = self::requests()[$operation];
        $isKey = str_ends_with($caller, 'Key');

        return match (true) {
            $caller === 'anonymous' => [401, 'unauthenticated'],
            $method !== 'GET' && str_ends_with($caller, 'ReadOnlyKey') && ! in_array('readOnly', $options, true) => [403, 'insufficient-key-scope'],
            $isKey && in_array('session', $options, true) => [403, 'session-required'],
            $caller === 'restrictedSession' => [403, 'pending-action-required'],
            $rules[self::role($caller)] === 'forbidden' => [403, 'forbidden'],
            default => [$success, null],
        };
    }

    /**
     * @param  array<string, mixed>|string|null  $body
     * @return TestResponse<Response>
     */
    private function request(string $caller, string $method, string $path, array|string|null $body): TestResponse
    {
        $headers = [];

        match ($caller) {
            'anonymous' => null,
            'restrictedSession' => $this->signInAs('restricted', Role::Admin),
            'adminSession' => $this->signInAs('admin', Role::Admin),
            'domainAdminSession' => $this->signInAs('reseller', Role::DomainAdmin),
            'mailboxSession' => $this->signInAs('alice@example.org', Role::MailboxUser),
            default => $headers['X-API-Key'] = $this->keys[$caller],
        };

        if (! isset($headers['X-API-Key']) && $method !== 'GET' && $caller !== 'anonymous') {
            $headers['X-CSRF-Token'] = $this->csrfToken();
        }

        if ($body === self::PNG) {
            $server = $this->transformHeadersToServerVars(['Accept' => 'application/json', 'Content-Type' => 'image/png'] + $headers);

            return $this->call($method, $path, [], [], [], $server, (string) file_get_contents(__DIR__.'/../../fixtures/logos/valid/logo-300x80.png'));
        }

        return match (true) {
            $body === null && $method === 'POST' => $this->postEmpty($path, $headers),
            $body === null || is_string($body) => $this->json($method, $path, [], $headers),
            $method === 'PATCH' => $this->patchMerge($path, $body, $headers),
            default => $this->json($method, $path, $body, $headers),
        };
    }

    /**
     * A snapshot of every stored value this feature can change: mailcow's keys, the API-owned keys,
     * the shared files and the rows.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $redis = $this->redis();
        // The API's own keys (sessions, throttling, caches) except the settings it owns.
        $keys = array_filter((array) $redis->keys('*'), fn (mixed $key): bool => is_string($key) && (! str_starts_with($key, 'restv2:') || $key === 'restv2:brand-colors' || str_starts_with($key, 'restv2:license')));
        sort($keys);
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $redis->dump($key);
        }

        $files = [];

        foreach (array_merge(glob($this->files->mapDir().'/*') ?: [], glob($this->files->sieveDir().'/*') ?: []) as $file) {
            $files[$file] = sha1((string) file_get_contents($file));
        }

        return ['redis' => array_map(fn (mixed $v): string => is_string($v) ? sha1($v) : '', $values), 'files' => $files, 'settingsmap' => DB::table('settingsmap')->count()];
    }

    #[DataProvider('cases')]
    public function test_every_operation_for_every_caller(string $operation, string $caller): void
    {
        [$method, $path, $body] = self::requests()[$operation];
        [$status, $type] = self::expected($operation, $caller);
        $path = strtr($path, $this->values);

        $before = DB::table('restv2_audit_events')->where('outcome', 'failure')->count();
        $snapshot = $this->snapshot();
        $response = $this->request($caller, $method, $path, $body);

        $response->assertStatus($status);

        if ($type !== null) {
            $response->assertJsonPath('type', '/api/v2/problems/'.$type);
        }

        $refused = $status >= 400;
        $audited = $method !== 'GET' && $refused && $caller !== 'anonymous' && $operation !== 'previewMassMailRecipients';
        $this->assertSame($before + ($audited ? 1 : 0), DB::table('restv2_audit_events')->where('outcome', 'failure')->count(), 'failure audit records');

        if ($refused) {
            $this->assertSame($snapshot, $this->snapshot(), 'A refused request changes nothing.');
        }
    }
}
