<?php

namespace Tests\Feature\PublicConfig;

use Illuminate\Support\Facades\Redis;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\TestCase;

/**
 * T091 (FR-042–FR-044, AS-1–AS-3, SC-008, R15): the branding, login page options and languages
 * of mailcow's legacy customisation, readable before sign-in and read on every request.
 */
class PublicConfigTest extends TestCase
{
    use ApiKeys;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /**
     * Writes mailcow's Redis keys as legacy `customize('edit', …)` writes them.
     *
     * @param  array<string, string>  $values
     */
    private function legacySet(array $values): void
    {
        foreach ($values as $key => $value) {
            Redis::connection('mailcow')->set($key, $value);
        }
    }

    public function test_the_defaults_without_customisation(): void
    {
        $response = $this->getJson('/api/v2/public-config')->assertOk();

        $response->assertJsonPath('data', [
            'pageTitle' => 'mail.example.org - mail UI',
            'productName' => 'mail.example.org - mail UI',
            'logos' => [
                'light' => ['url' => '/img/cow_mailcow.svg', 'isDefault' => true],
                'dark' => ['url' => '/img/cow_mailcow.svg', 'isDefault' => true],
            ],
            'loginHelpHtml' => null,
            'footerHtml' => null,
            'announcement' => ['text' => null, 'type' => null, 'active' => false],
            'loginPage' => [
                'hideAdminLogin' => false,
                'hideDomainAdminLogin' => false,
                'hideMailboxUserLogin' => false,
                'forceSingleSignOn' => false,
            ],
            // tests/fixtures/mailcow-inc/vars.local.inc.php
            'languages' => [
                ['code' => 'cs-cz', 'name' => 'Čeština (Czech)'],
                ['code' => 'en-gb', 'name' => 'English'],
                ['code' => 'sk-sk', 'name' => 'Slovenčina (Slovak)'],
            ],
            'defaultLanguage' => 'cs-cz',
            'externalIdentityProvider' => ['configured' => false, 'singleSignOnAvailable' => false],
            'appsLabel' => null,
            'appLinks' => [[
                'name' => 'Webmail',
                'url' => '/SOGo/so',
                'signedInUrl' => '/SOGo/so',
                'description' => null,
                'showOnLoginPage' => false,
                'source' => 'builtIn',
            ]],
        ]);
        // Any cache may store it but must revalidate (directives in Symfony's order).
        $response->assertHeader('Cache-Control', 'no-cache, public');
        $this->assertNotEmpty($response->headers->get('ETag'));
        $this->assertSame([], $response->headers->getCookies(), 'no session is started');
    }

    public function test_the_fork_defaults_are_server_settings(): void
    {
        config([
            'restv2.default_ui_name' => 'FeldHost™ Mailhosting UI',
            'restv2.default_logo_url' => '/img/feldhost_logo.png',
            'restv2.default_logo_dark_url' => '/img/feldhost_logo_white.png',
        ]);

        $this->getJson('/api/v2/public-config')
            ->assertOk()
            ->assertJsonPath('data.pageTitle', 'FeldHost™ Mailhosting UI')
            ->assertJsonPath('data.productName', 'FeldHost™ Mailhosting UI')
            ->assertJsonPath('data.logos.light', ['url' => '/img/feldhost_logo.png', 'isDefault' => true])
            ->assertJsonPath('data.logos.dark', ['url' => '/img/feldhost_logo_white.png', 'isDefault' => true]);
    }

    public function test_the_values_saved_in_the_legacy_ui(): void
    {
        $this->legacySet([
            'TITLE_NAME' => htmlspecialchars('A & B "Mail"'),
            'MAIN_NAME' => htmlspecialchars('A & B'),
            'HELP_TEXT' => '<p>Call <b>us</b> &amp; win</p>',
            'UI_FOOTER' => '<a href="/imprint">Imprint</a>',
            'UI_ANNOUNCEMENT_TEXT' => 'Maintenance on Sunday <02:00>',
            'UI_ANNOUNCEMENT_TYPE' => 'warning',
            'UI_ANNOUNCEMENT_ACTIVE' => '1',
            'CUSTOM_LOGIN' => json_encode(['hide_admin_quicklink' => 1, 'hide_domainadmin_quicklink' => 0, 'hide_user_quicklink' => '1', 'force_sso' => 1]),
            'MAIN_LOGO' => self::PNG,
        ]);

        $response = $this->getJson('/api/v2/public-config')->assertOk();

        $response->assertJsonPath('data.pageTitle', 'A & B "Mail"')
            ->assertJsonPath('data.productName', 'A & B')
            ->assertJsonPath('data.loginHelpHtml', '<p>Call <b>us</b> &amp; win</p>')
            ->assertJsonPath('data.footerHtml', '<a href="/imprint">Imprint</a>')
            ->assertJsonPath('data.announcement', ['text' => 'Maintenance on Sunday <02:00>', 'type' => 'warning', 'active' => true])
            ->assertJsonPath('data.loginPage', [
                'hideAdminLogin' => true,
                'hideDomainAdminLogin' => false,
                'hideMailboxUserLogin' => true,
                'forceSingleSignOn' => true,
            ])
            ->assertJsonPath('data.logos.light', [
                'url' => '/api/v2/public-config/logos/light?v='.substr(hash('sha256', self::PNG), 0, 16),
                'isDefault' => false,
            ])
            ->assertJsonPath('data.logos.dark', ['url' => '/img/cow_mailcow.svg', 'isDefault' => true]);
    }

    public function test_the_legacy_impress_is_the_footer(): void
    {
        // Legacy moves UI_IMPRESS into UI_FOOTER when it reads the texts; the API only reads.
        $this->legacySet(['UI_IMPRESS' => '<p>Old impress</p>']);
        $this->getJson('/api/v2/public-config')->assertJsonPath('data.footerHtml', '<p>Old impress</p>');

        $this->legacySet(['UI_FOOTER' => '<p>Footer</p>']);
        $this->getJson('/api/v2/public-config')->assertJsonPath('data.footerHtml', '<p>Old impress</p>');
        $this->assertSame('<p>Old impress</p>', Redis::connection('mailcow')->get('UI_IMPRESS'), 'nothing is written');

        Redis::connection('mailcow')->del('UI_IMPRESS');
        $this->getJson('/api/v2/public-config')->assertJsonPath('data.footerHtml', '<p>Footer</p>');
    }

    public function test_unknown_or_empty_values_fall_back(): void
    {
        $this->legacySet([
            'TITLE_NAME' => '',
            'HELP_TEXT' => '',
            'UI_ANNOUNCEMENT_TEXT' => 'Hello',
            'UI_ANNOUNCEMENT_TYPE' => 'success',
            'UI_ANNOUNCEMENT_ACTIVE' => '0',
            'CUSTOM_LOGIN' => 'not json',
            'MAIN_LOGO_DARK' => 'data:text/html;base64,PHNjcmlwdD4=',
        ]);

        $this->getJson('/api/v2/public-config')
            ->assertOk()
            ->assertJsonPath('data.pageTitle', 'mail.example.org - mail UI')
            ->assertJsonPath('data.loginHelpHtml', null)
            ->assertJsonPath('data.announcement', ['text' => 'Hello', 'type' => null, 'active' => false])
            ->assertJsonPath('data.loginPage.hideAdminLogin', false)
            ->assertJsonPath('data.logos.dark', ['url' => '/img/cow_mailcow.svg', 'isDefault' => true]);
    }

    public function test_a_configured_identity_provider_is_reported(): void
    {
        $this->mailcow()->identityProvider('keycloak');

        // Single sign-on itself arrives with feature 012.
        $this->getJson('/api/v2/public-config')
            ->assertJsonPath('data.externalIdentityProvider', ['configured' => true, 'singleSignOnAvailable' => false]);

        $this->mailcow()->identityProvider('none');
        $this->getJson('/api/v2/public-config')->assertJsonPath('data.externalIdentityProvider.configured', false);
    }

    public function test_a_change_in_the_legacy_ui_is_visible_on_the_next_request(): void
    {
        $this->legacySet(['UI_ANNOUNCEMENT_TEXT' => 'First']);
        $first = $this->getJson('/api/v2/public-config')->assertJsonPath('data.announcement.text', 'First');

        $this->legacySet(['UI_ANNOUNCEMENT_TEXT' => 'Second']);
        $second = $this->getJson('/api/v2/public-config')->assertJsonPath('data.announcement.text', 'Second');

        $this->assertNotSame($first->headers->get('ETag'), $second->headers->get('ETag'));
    }

    public function test_a_conditional_request_is_answered_not_modified(): void
    {
        $etag = (string) $this->getJson('/api/v2/public-config')->headers->get('ETag');

        $response = $this->getJson('/api/v2/public-config', ['If-None-Match' => $etag])->assertStatus(304);

        $response->assertHeader('ETag', $etag);
        $this->assertSame('', $response->getContent());

        $this->getJson('/api/v2/public-config', ['If-None-Match' => '"other"'])->assertOk();
    }

    public function test_it_contains_no_secret_or_account_data(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $this->mailcow()->mailboxWithDomain('alice@example.org');

        $content = (string) $this->getJson('/api/v2/public-config')->assertOk()->getContent();

        // (The dev database password, "mailcow", is part of the default logo's file name.)
        foreach ([(string) config('database.redis.restv2.password'), (string) config('app.key'), 'alice@example.org', '"admin"'] as $secret) {
            $this->assertNotSame('', $secret);
            $this->assertStringNotContainsString($secret, $content);
        }
    }

    public function test_it_is_public_but_an_invalid_api_key_is_refused(): void
    {
        $this->mailcow()->admin('admin', 'x');

        $this->getJson('/api/v2/public-config', $this->keyHeader($this->apiKey('admin', 'readOnly')))->assertOk();
        $this->getJson('/api/v2/public-config', ['X-API-Key' => 'bogus'])
            ->assertUnauthorized()
            ->assertJsonPath('type', '/api/v2/problems/api-key-invalid');
    }
}
