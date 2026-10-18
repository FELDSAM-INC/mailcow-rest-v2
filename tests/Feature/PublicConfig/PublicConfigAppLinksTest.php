<?php

namespace Tests\Feature\PublicConfig;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * T093 (FR-056, AS-4, R15): the app links of the legacy login page and apps menu: the built-in
 * apps of `$MAILCOW_APPS` first, then the operator's links (Redis `APP_LINKS`), with legacy's
 * hiding and SKIP_SOGO rules. `%u` is never substituted.
 */
class PublicConfigAppLinksTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private function appLinks(): array
    {
        return $this->getJson('/api/v2/public-config')->assertOk()->json('data.appLinks');
    }

    /**
     * Stores the operator links as legacy `customize('edit', 'app_links')` does, plus an old-style
     * entry from an earlier mailcow release.
     */
    private function storeOperatorLinks(): void
    {
        Redis::connection('mailcow')->set('APP_LINKS', json_encode([
            ['Helpdesk' => ['link' => 'https://h.example/', 'user_link' => 'https://h.example/?u=%u', 'hide' => true]],
            ['Wiki' => 'https://wiki.example/'],
            ['Calendar' => ['link' => '/SOGo/so/calendar', 'user_link' => '', 'hide' => false]],
        ]));
    }

    public function test_the_upstream_default_is_webmail_hidden_on_the_login_page(): void
    {
        $this->assertSame([[
            'name' => 'Webmail',
            'url' => '/SOGo/so',
            'signedInUrl' => '/SOGo/so',
            'description' => null,
            'showOnLoginPage' => false,
            'source' => 'builtIn',
        ]], $this->appLinks());
    }

    public function test_built_in_apps_keep_their_order_and_the_user_placeholder(): void
    {
        config(['restv2.vars_overrides.MAILCOW_APPS' => json_encode([
            ['name' => 'Webmail', 'link' => '/SOGo/so', 'user_link' => '/SOGo/so', 'hide' => true],
            ['name' => 'Portal', 'link' => '/portal', 'user_link' => '/portal/?user=%u', 'description' => 'Customer portal'],
        ])]);

        $links = $this->appLinks();

        $this->assertSame(['Webmail', 'Portal'], array_column($links, 'name'));
        $this->assertSame([
            'name' => 'Portal',
            'url' => '/portal',
            'signedInUrl' => '/portal/?user=%u',
            'description' => 'Customer portal',
            'showOnLoginPage' => true,
            'source' => 'builtIn',
        ], $links[1]);
    }

    public function test_operator_links_follow_the_built_in_apps(): void
    {
        $this->storeOperatorLinks();

        $links = $this->appLinks();

        $this->assertSame(['Webmail', 'Helpdesk', 'Wiki', 'Calendar'], array_column($links, 'name'));
        $this->assertSame([
            'name' => 'Helpdesk',
            'url' => 'https://h.example/',
            'signedInUrl' => 'https://h.example/?u=%u',
            'description' => null,
            'showOnLoginPage' => false,
            'source' => 'operator',
        ], $links[1]);
        $this->assertSame([
            'name' => 'Wiki',
            'url' => 'https://wiki.example/',
            'signedInUrl' => 'https://wiki.example/',
            'description' => null,
            'showOnLoginPage' => true,
            'source' => 'operator',
        ], $links[2]);
        // An empty signed-in address falls back to the login page address.
        $this->assertSame('/SOGo/so/calendar', $links[3]['signedInUrl']);
    }

    public function test_skip_sogo_drops_built_in_sogo_apps_but_keeps_operator_links(): void
    {
        config([
            'restv2.skip_sogo' => true,
            'restv2.vars_overrides.MAILCOW_APPS' => json_encode([
                ['name' => 'Webmail', 'link' => '/SOGo/so', 'user_link' => '/SOGo/so', 'hide' => true],
                ['name' => 'Groupware', 'link' => '/portal', 'user_link' => '/sogo/so/%u'],
                ['name' => 'Portal', 'link' => '/portal'],
            ]),
        ]);
        $this->storeOperatorLinks();

        $this->assertSame(['Portal', 'Helpdesk', 'Wiki', 'Calendar'], array_column($this->appLinks(), 'name'));
    }

    public function test_the_apps_menu_label(): void
    {
        $this->getJson('/api/v2/public-config')->assertJsonPath('data.appsLabel', null);

        Redis::connection('mailcow')->set('APPS_NAME', htmlspecialchars('Tools & Apps'));

        $this->getJson('/api/v2/public-config')->assertJsonPath('data.appsLabel', 'Tools & Apps');
    }

    public function test_malformed_operator_links_give_the_built_in_apps_only(): void
    {
        $log = Log::spy();
        Redis::connection('mailcow')->set('APP_LINKS', '{not json');

        $this->assertSame(['Webmail'], array_column($this->appLinks(), 'name'));

        $log->shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'APP_LINKS'))->once();
    }
}
