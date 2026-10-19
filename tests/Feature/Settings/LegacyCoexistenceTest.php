<?php

namespace Tests\Feature\Settings;

use Tests\Support\Settings\SettingsTestHelpers;
use Tests\TestCase;

/**
 * Feature 013 T092 (SC-001, FR-010): the Redis-backed settings coexist with the legacy UI. Values
 * written as legacy's `edit` branches write them are returned by the API; values written by the
 * API are read by ports of legacy's `get` branches (customize('get', …), quota_notification('get'),
 * quota_notification_bcc('get'), cors('get'), password_complexity('get'),
 * reset_password('get_notification')) exactly as after the same change made in the legacy UI; and
 * the version tag changes after each legacy write.
 */
class LegacyCoexistenceTest extends TestCase
{
    use SettingsTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailcow()->domain('example.org');
        $this->asAdmin();
    }

    /**
     * Runs the legacy write and reads legacy's view of it, clears the keys, makes the same change
     * through the API and checks that legacy reads the same.
     *
     * @param  list<string>  $keys
     */
    private function assertLegacyReadsTheSame(array $keys, callable $legacyEdit, callable $apiWrite, callable $legacyGet): void
    {
        $legacyEdit();
        $expected = $legacyGet();
        $this->redis()->del($keys);

        $apiWrite();

        $this->assertSame($expected, $legacyGet());
    }

    private function assertTagChangesAfterLegacyWrite(string $uri, callable $legacyWrite): void
    {
        $before = (string) $this->getJson($uri)->assertOk()->json('data.etag');
        $legacyWrite();
        $this->assertNotSame($before, (string) $this->getJson($uri)->assertOk()->json('data.etag'), $uri);
    }

    public function test_ui_texts(): void
    {
        $values = ['title_name' => 'Acme & "Co"', 'main_name' => 'Mail <b>', 'apps_name' => 'Apps', 'help_text' => '<p>Help</p>', 'ui_footer' => '<small>F</small>',
            'ui_announcement_text' => '<b>Down</b>', 'ui_announcement_type' => 'warning', 'ui_announcement_active' => '1'];
        $api = ['pageTitle' => 'Acme & "Co"', 'productName' => 'Mail <b>', 'appsHeading' => 'Apps', 'loginHelpHtml' => '<p>Help</p>', 'footerHtml' => '<small>F</small>',
            'announcement' => ['text' => '<b>Down</b>', 'type' => 'warning', 'active' => true]];
        $keys = ['TITLE_NAME', 'MAIN_NAME', 'APPS_NAME', 'HELP_TEXT', 'UI_FOOTER', 'UI_ANNOUNCEMENT_TEXT', 'UI_ANNOUNCEMENT_TYPE', 'UI_ANNOUNCEMENT_ACTIVE'];

        $this->assertTagChangesAfterLegacyWrite('/api/v2/settings/ui-texts', fn () => $this->legacyEditUiTexts($values));
        $data = (array) $this->getJson('/api/v2/settings/ui-texts')->json('data');
        $this->assertSame($api, array_intersect_key($data, $api));

        $this->assertLegacyReadsTheSame($keys, fn () => $this->legacyEditUiTexts($values), fn () => $this->send('PATCH', '/api/v2/settings/ui-texts', $api)->assertOk(), fn (): array => $this->legacyGetUiTexts());
    }

    public function test_login_page_options(): void
    {
        $legacy = ['hide_user_quicklink' => '1', 'hide_domainadmin_quicklink' => '0', 'hide_admin_quicklink' => '1', 'force_sso' => '1'];
        $api = ['hideAdminLogin' => true, 'hideDomainAdminLogin' => false, 'hideMailboxUserLogin' => true, 'forceSingleSignOn' => true];

        $this->assertTagChangesAfterLegacyWrite('/api/v2/settings/login-page', fn () => $this->legacyEditCustomLogin($legacy));
        $this->assertSame($api, array_intersect_key((array) $this->getJson('/api/v2/settings/login-page')->json('data'), $api));

        $this->assertLegacyReadsTheSame(['CUSTOM_LOGIN'], fn () => $this->legacyEditCustomLogin($legacy), fn () => $this->send('PATCH', '/api/v2/settings/login-page', $api)->assertOk(), function (): array {
            $raw = $this->sharedGet('CUSTOM_LOGIN');

            return $raw ? (array) json_decode($raw, true) : [];
        });
        $this->assertSame('{"hide_user_quicklink":1,"hide_domainadmin_quicklink":0,"hide_admin_quicklink":1,"force_sso":1}', $this->sharedGet('CUSTOM_LOGIN'));
    }

    public function test_app_links(): void
    {
        $legacy = ['app' => ['Wiki', 'Ticket'], 'href' => ['https://wiki.example.org/', '/tickets'], 'user_href' => ['https://wiki.example.org/me', ''], 'hide' => ['0', '1']];
        $api = [
            ['name' => 'Wiki', 'url' => 'https://wiki.example.org/', 'signedInUrl' => 'https://wiki.example.org/me', 'showOnLoginPage' => true],
            ['name' => 'Ticket', 'url' => '/tickets', 'signedInUrl' => null, 'showOnLoginPage' => false],
        ];

        $this->assertTagChangesAfterLegacyWrite('/api/v2/settings/app-links', fn () => $this->legacyEditAppLinks($legacy));
        $this->assertSame($api, $this->getJson('/api/v2/settings/app-links')->json('data.links'));

        $this->assertLegacyReadsTheSame(['APP_LINKS'], fn () => $this->legacyEditAppLinks($legacy), fn () => $this->send('PATCH', '/api/v2/settings/app-links', ['links' => $api])->assertOk(), fn (): mixed => $this->sharedGet('APP_LINKS'));

        // An old-style entry written by an older release is converted as legacy converts it.
        $this->sharedValue('APP_LINKS', '[{"Old":"https:\/\/old.example.org"}]');
        $this->assertSame([['name' => 'Old', 'url' => 'https://old.example.org', 'signedInUrl' => 'https://old.example.org', 'showOnLoginPage' => true]], $this->getJson('/api/v2/settings/app-links')->json('data.links'));
    }

    public function test_ip_check(): void
    {
        $this->assertTagChangesAfterLegacyWrite('/api/v2/settings/ip-check', fn () => $this->sharedValue('IP_CHECK', '1'));
        $this->getJson('/api/v2/settings/ip-check')->assertJsonPath('data.enabled', true);

        $this->assertLegacyReadsTheSame(['IP_CHECK'], fn () => $this->sharedValue('IP_CHECK', '1'), fn () => $this->send('PATCH', '/api/v2/settings/ip-check', ['enabled' => true])->assertOk(), fn (): mixed => ($v = $this->sharedGet('IP_CHECK')) ? $v : 0);
    }

    public function test_password_policy(): void
    {
        $legacy = ['length' => '10', 'chars' => '1', 'special_chars' => '0', 'lowerupper' => '1', 'numbers' => '1'];

        $this->assertTagChangesAfterLegacyWrite('/api/v2/password-policy', fn () => $this->redis()->hMSet('PASSWD_POLICY', $legacy));
        $this->getJson('/api/v2/password-policy')->assertJsonPath('data.minLength', 10)->assertJsonPath('data.requireDigits', true);

        $this->assertLegacyReadsTheSame(['PASSWD_POLICY'], fn () => $this->redis()->hMSet('PASSWD_POLICY', $legacy), fn () => $this->send('PATCH', '/api/v2/password-policy', [
            'minLength' => 10, 'requireLetters' => true, 'requireSpecialCharacters' => false, 'requireMixedCase' => true, 'requireDigits' => true,
        ])->assertOk(), fn (): array => array_map(fn (mixed $v): mixed => $this->redis()->hGet('PASSWD_POLICY', (string) $v), array_combine(array_keys($legacy), array_keys($legacy))));
    }

    public function test_password_reset_notification(): void
    {
        $values = ['PW_RESET_FROM' => 'reset@example.org', 'PW_RESET_SUBJ' => 'Reset now', 'PW_RESET_HTML' => '<p>{{link}}</p>', 'PW_RESET_TEXT' => 'Open {{link}}'];

        $this->assertTagChangesAfterLegacyWrite('/api/v2/settings/password-reset-notification', fn () => $this->redis()->mSet($values));
        $this->getJson('/api/v2/settings/password-reset-notification')->assertJsonPath('data.sender', 'reset@example.org')->assertJsonPath('data.htmlTemplate', '<p>{{link}}</p>');

        $this->assertLegacyReadsTheSame(array_keys($values), fn () => $this->redis()->mSet($values), fn () => $this->send('PATCH', '/api/v2/settings/password-reset-notification', [
            'sender' => 'reset@example.org', 'subject' => 'Reset now', 'htmlTemplate' => '<p>{{link}}</p>', 'textTemplate' => 'Open {{link}}',
        ])->assertOk(), fn (): array => [
            'from' => $this->sharedGet('PW_RESET_FROM'), 'subject' => $this->sharedGet('PW_RESET_SUBJ'),
            'html_tmpl' => htmlspecialchars((string) $this->sharedGet('PW_RESET_HTML')), 'text_tmpl' => htmlspecialchars((string) $this->sharedGet('PW_RESET_TEXT')),
        ]);
    }

    public function test_quota_notification(): void
    {
        $values = ['QW_SENDER' => 'quota@example.org', 'QW_SUBJ' => 'Almost full', 'QW_HTML' => '<p>{{percent}}%</p>'];

        $this->assertTagChangesAfterLegacyWrite('/api/v2/settings/quota-notification', fn () => $this->redis()->mSet($values));
        $this->getJson('/api/v2/settings/quota-notification')->assertJsonPath('data.subject', 'Almost full')->assertJsonPath('data.htmlTemplate', '<p>{{percent}}%</p>');

        $this->assertLegacyReadsTheSame(array_keys($values), fn () => $this->redis()->mSet($values), fn () => $this->send('PATCH', '/api/v2/settings/quota-notification', [
            'sender' => 'quota@example.org', 'subject' => 'Almost full', 'htmlTemplate' => '<p>{{percent}}%</p>',
        ])->assertOk(), fn (): array => [
            'subject' => $this->sharedGet('QW_SUBJ'), 'sender' => $this->sharedGet('QW_SENDER'), 'html_tmpl' => htmlspecialchars((string) $this->sharedGet('QW_HTML')),
        ]);
    }

    public function test_quota_notification_copy_recipients(): void
    {
        $legacy = fn () => $this->redis()->hSet('QW_BCC', 'example.org', (string) json_encode(['bcc_rcpts' => ['it@example.org', 'boss@example.org'], 'active' => 1]));
        $uri = '/api/v2/domains/example.org/quota-notification-recipients';

        $this->assertTagChangesAfterLegacyWrite($uri, $legacy);
        $this->getJson($uri)->assertJsonPath('data.recipients', ['it@example.org', 'boss@example.org'])->assertJsonPath('data.active', true);

        $this->assertLegacyReadsTheSame(['QW_BCC'], $legacy, fn () => $this->send('PATCH', $uri, ['recipients' => ['it@example.org', 'boss@example.org'], 'active' => true])->assertOk(),
            fn (): mixed => json_decode((string) $this->redis()->hGet('QW_BCC', 'example.org'), true));
    }

    public function test_legacy_api_cors(): void
    {
        $legacy = fn () => $this->redis()->hMSet('CORS_SETTINGS', ['allowed_origins' => implode(', ', ['https://portal.example.org', '*']), 'allowed_methods' => implode(', ', ['GET', 'POST'])]);

        $this->assertTagChangesAfterLegacyWrite('/api/v2/settings/legacy-api-cors', $legacy);
        $this->getJson('/api/v2/settings/legacy-api-cors')->assertJsonPath('data.allowedOrigins', ['https://portal.example.org', '*'])->assertJsonPath('data.allowedMethods', ['GET', 'POST']);

        $this->assertLegacyReadsTheSame(['CORS_SETTINGS'], $legacy, fn () => $this->send('PATCH', '/api/v2/settings/legacy-api-cors', ['allowedOrigins' => ['https://portal.example.org', '*'], 'allowedMethods' => ['GET', 'POST']])->assertOk(),
            fn (): array => $this->legacyGetCors());
    }

    public function test_logos(): void
    {
        $png = 'data:image/png;base64,'.base64_encode((string) file_get_contents(base_path('tests/fixtures/logos/valid/logo-300x80.png')));

        $this->assertTagChangesAfterLegacyWrite('/api/v2/settings/logos/light', fn () => $this->sharedValue('MAIN_LOGO', $png));
        $this->getJson('/api/v2/settings/logos/light')->assertJsonPath('data.mediaType', 'image/png');

        $this->assertLegacyReadsTheSame(['MAIN_LOGO_DARK'], fn () => $this->sharedValue('MAIN_LOGO_DARK', $png), fn () => $this->sendRaw('PUT', '/api/v2/settings/logos/dark', (string) file_get_contents(base_path('tests/fixtures/logos/valid/logo-300x80.png')), 'image/png')->assertOk(),
            fn (): mixed => $this->sharedGet('MAIN_LOGO_DARK'));
    }

    /*
    | Ports of legacy's branches (data/web/inc/functions*.inc.php, upstream 2026-09a).
    */

    /**
     * customize('edit', 'ui_texts').
     *
     * @param  array<string, string>  $data
     */
    private function legacyEditUiTexts(array $data): void
    {
        $type = in_array($data['ui_announcement_type'], ['info', 'warning', 'danger'], true) ? $data['ui_announcement_type'] : '';
        $this->redis()->mSet([
            'TITLE_NAME' => htmlspecialchars($data['title_name']),
            'MAIN_NAME' => htmlspecialchars($data['main_name']),
            'APPS_NAME' => htmlspecialchars($data['apps_name']),
            'HELP_TEXT' => $data['help_text'],
            'UI_FOOTER' => $data['ui_footer'],
            'UI_ANNOUNCEMENT_TEXT' => $data['ui_announcement_text'],
            'UI_ANNOUNCEMENT_TYPE' => $type,
            'UI_ANNOUNCEMENT_ACTIVE' => $data['ui_announcement_active'] !== '' && $data['ui_announcement_active'] !== '0' ? '1' : '0',
        ]);
    }

    /**
     * customize('get', 'ui_texts') without the UI_IMPRESS migration.
     *
     * @return array<string, mixed>
     */
    private function legacyGetUiTexts(): array
    {
        $host = strtolower((string) config('restv2.mailcow_hostname'));
        $get = fn (string $key): ?string => $this->sharedGet($key);

        return [
            'title_name' => ($v = $get('TITLE_NAME')) ? $v : "{$host} - mail UI",
            'main_name' => ($v = $get('MAIN_NAME')) ? $v : "{$host} - mail UI",
            'apps_name' => ($v = $get('APPS_NAME')) ? $v : 'Apps',
            'help_text' => ($v = $get('HELP_TEXT')) ? $v : false,
            'ui_footer' => ($v = $get('UI_FOOTER')) ? $v : false,
            'ui_announcement_text' => ($v = $get('UI_ANNOUNCEMENT_TEXT')) ? $v : false,
            'ui_announcement_type' => ($v = $get('UI_ANNOUNCEMENT_TYPE')) ? $v : false,
            'ui_announcement_active' => $get('UI_ANNOUNCEMENT_ACTIVE') == 1 ? 1 : 0,
        ];
    }

    /**
     * customize('edit', 'custom_login').
     *
     * @param  array<string, string>  $data
     */
    private function legacyEditCustomLogin(array $data): void
    {
        $this->sharedValue('CUSTOM_LOGIN', (string) json_encode([
            'hide_user_quicklink' => $data['hide_user_quicklink'] == '1' ? 1 : 0,
            'hide_domainadmin_quicklink' => $data['hide_domainadmin_quicklink'] == '1' ? 1 : 0,
            'hide_admin_quicklink' => $data['hide_admin_quicklink'] == '1' ? 1 : 0,
            'force_sso' => $data['force_sso'] == '1' ? 1 : 0,
        ]));
    }

    /**
     * customize('edit', 'app_links').
     *
     * @param  array{app: list<string>, href: list<string>, user_href: list<string>, hide: list<string>}  $data
     */
    private function legacyEditAppLinks(array $data): void
    {
        $out = [];

        foreach ($data['app'] as $i => $app) {
            $out[] = [$app => ['link' => $data['href'][$i], 'user_link' => $data['user_href'][$i], 'hide' => ! ($data['hide'][$i] === '0')]];
        }

        $this->sharedValue('APP_LINKS', (string) json_encode($out));
    }

    /**
     * cors('get').
     *
     * @return array{allowed_origins: string, allowed_methods: string}
     */
    private function legacyGetCors(): array
    {
        $values = $this->redis()->hMGet('CORS_SETTINGS', ['allowed_origins', 'allowed_methods']);
        $base = 'https://'.strtolower((string) config('restv2.mailcow_hostname'));
        $values = is_array($values) ? $values : [];

        return [
            'allowed_origins' => empty($values['allowed_origins']) ? $base : (string) $values['allowed_origins'],
            'allowed_methods' => empty($values['allowed_methods']) ? 'GET, POST, PUT, DELETE, OPTION' : (string) $values['allowed_methods'],
        ];
    }
}
