<?php

namespace Tests\Feature\Settings;

use Tests\Support\Settings\SettingsTestHelpers;
use Tests\TestCase;

/**
 * Feature 013 T029 (FR-025 to FR-028, AS-1.9, AS-1.10): the custom app links in legacy's
 * `APP_LINKS` (a JSON list of one-key maps, written exactly as legacy's `json_encode()`), validated
 * entry by entry; the built-in links are read-only; the public configuration shows the result.
 */
class AppLinkSettingsTest extends TestCase
{
    use SettingsTestHelpers;

    private const HELPDESK = ['name' => 'Helpdesk', 'url' => 'https://help.example.org/', 'signedInUrl' => 'https://help.example.org/?u=%u', 'showOnLoginPage' => true];

    public function test_a_fresh_server_has_no_custom_links_and_shows_the_built_in_ones(): void
    {
        $this->asAdmin();

        $response = $this->getJson('/api/v2/settings/app-links')->assertOk();

        $response->assertJsonPath('data.links', [])
            ->assertJsonPath('data.builtInLinks.0.name', 'Webmail')
            ->assertJsonPath('data.builtInLinks.0.url', '/SOGo/so')
            ->assertJsonPath('data.builtInLinks.0.source', 'builtIn');
        $response->assertHeader('ETag', (string) $response->json('data.etag'));
    }

    public function test_built_in_webmail_links_are_left_out_without_the_groupware(): void
    {
        $this->asAdmin();
        config(['restv2.skip_sogo' => true]);

        $this->getJson('/api/v2/settings/app-links')->assertOk()->assertJsonPath('data.builtInLinks', []);
    }

    public function test_links_are_written_exactly_as_legacy_writes_them_and_reach_the_public_configuration(): void
    {
        $this->asAdmin();
        $wiki = ['name' => 'Wiki – Ünïcode', 'url' => '/wiki', 'signedInUrl' => null, 'showOnLoginPage' => false];

        $this->send('PATCH', '/api/v2/settings/app-links', ['links' => [self::HELPDESK, $wiki]])->assertOk()
            ->assertJsonPath('data.links', [self::HELPDESK, $wiki]);

        $legacy = json_encode([
            ['Helpdesk' => ['link' => 'https://help.example.org/', 'user_link' => 'https://help.example.org/?u=%u', 'hide' => false]],
            ['Wiki – Ünïcode' => ['link' => '/wiki', 'user_link' => '', 'hide' => true]],
        ]);
        $this->assertSame($legacy, $this->sharedGet('APP_LINKS'));
        $this->assertStringContainsString('https:\/\/help.example.org\/', (string) $this->sharedGet('APP_LINKS'));

        $this->forgetCookies();
        $links = (array) $this->getJson('/api/v2/public-config')->assertOk()->json('data.appLinks');
        $this->assertSame(['Webmail', 'Helpdesk', 'Wiki – Ünïcode'], array_column($links, 'name'));
        $this->assertSame(['name' => 'Helpdesk', 'url' => 'https://help.example.org/', 'signedInUrl' => 'https://help.example.org/?u=%u', 'description' => null, 'showOnLoginPage' => true, 'source' => 'operator'], $links[1]);
        $this->assertSame('/wiki', $links[2]['signedInUrl']);
        $this->assertFalse($links[2]['showOnLoginPage']);
    }

    public function test_old_style_legacy_entries_are_returned_converted(): void
    {
        $this->asAdmin();
        $this->sharedValue('APP_LINKS', '[{"Old":"https:\/\/old.example.org"},{"New":{"link":"\/x","user_link":"","hide":true}}]');

        $this->getJson('/api/v2/settings/app-links')->assertOk()->assertJsonPath('data.links', [
            ['name' => 'Old', 'url' => 'https://old.example.org', 'signedInUrl' => 'https://old.example.org', 'showOnLoginPage' => true],
            ['name' => 'New', 'url' => '/x', 'signedInUrl' => null, 'showOnLoginPage' => false],
        ]);
    }

    public function test_duplicate_names_are_accepted_and_the_order_kept(): void
    {
        $this->asAdmin();
        $b = ['name' => 'Same', 'url' => '/b', 'signedInUrl' => null, 'showOnLoginPage' => true];
        $a = ['name' => 'Same', 'url' => '/a', 'signedInUrl' => null, 'showOnLoginPage' => true];

        $this->send('PATCH', '/api/v2/settings/app-links', ['links' => [$b, $a]])->assertOk()->assertJsonPath('data.links', [$b, $a]);
    }

    public function test_an_empty_list_removes_every_custom_link(): void
    {
        $this->asAdmin();
        $this->send('PATCH', '/api/v2/settings/app-links', ['links' => [self::HELPDESK]])->assertOk();

        $this->send('PATCH', '/api/v2/settings/app-links', ['links' => []])->assertOk()->assertJsonPath('data.links', []);

        $this->assertSame('[]', $this->sharedGet('APP_LINKS'));
    }

    public function test_invalid_entries_are_refused_by_index_and_nothing_is_stored(): void
    {
        $this->asAdmin();
        $this->withoutRequestValidation();
        $this->sharedValue('APP_LINKS', '[]');

        $response = $this->send('PATCH', '/api/v2/settings/app-links', ['links' => [
            self::HELPDESK,
            ['name' => 'Evil', 'url' => 'javascript:alert(1)', 'signedInUrl' => null, 'showOnLoginPage' => true],
            ['name' => "Two\nlines", 'url' => '//evil.example.net', 'signedInUrl' => 'data:text/html,x', 'showOnLoginPage' => 'yes'],
            ['name' => '', 'url' => '/ok', 'signedInUrl' => null, 'showOnLoginPage' => true],
        ]])->assertStatus(422);

        $errors = (array) $response->json('errors');
        $this->assertSame('appLinkUrl', $errors['links.1.url'][0]['params']['format']);
        $this->assertSame('singleLine', $errors['links.2.name'][0]['params']['format']);
        $this->assertSame('appLinkUrl', $errors['links.2.url'][0]['params']['format']);
        $this->assertSame('appLinkUrl', $errors['links.2.signedInUrl'][0]['params']['format']);
        $this->assertSame('invalidType', $errors['links.2.showOnLoginPage'][0]['code']);
        $this->assertSame('required', $errors['links.3.name'][0]['code']);
        $this->assertArrayNotHasKey('links.0.url', $errors);
        $this->assertSame('[]', $this->sharedGet('APP_LINKS'));
    }

    public function test_more_than_50_links_are_invalid(): void
    {
        $this->asAdmin();
        $this->withoutRequestValidation();
        $links = array_fill(0, 51, ['name' => 'x', 'url' => '/x', 'signedInUrl' => null, 'showOnLoginPage' => true]);

        $this->send('PATCH', '/api/v2/settings/app-links', ['links' => $links])->assertStatus(422)->assertJsonPath('errors.links.0.code', 'tooMany')->assertJsonPath('errors.links.0.params.max', 50);

        $this->assertNull($this->sharedGet('APP_LINKS'));
    }

    public function test_demo_mode_refuses_changes_and_changes_are_audited_with_both_lists(): void
    {
        $this->asAdmin();
        config(['restv2.demo_mode' => true]);
        $this->send('PATCH', '/api/v2/settings/app-links', ['links' => [self::HELPDESK]])->assertForbidden()->assertJsonPath('type', '/api/v2/problems/demo-mode');

        config(['restv2.demo_mode' => false]);
        $this->send('PATCH', '/api/v2/settings/app-links', ['links' => [self::HELPDESK]])->assertOk();

        $records = $this->successesOf('appLinksUpdate');
        $this->assertCount(1, $records);
        $this->assertSame(['old' => [], 'new' => [self::HELPDESK]], $records[0]->details['changes']['links']);
    }

    public function test_an_outdated_tag_is_refused(): void
    {
        $this->asAdmin();
        $etag = (string) $this->getJson('/api/v2/settings/app-links')->json('data.etag');
        $this->sharedValue('APP_LINKS', '[{"Legacy":{"link":"\/l","user_link":"","hide":false}}]');

        $this->send('PATCH', '/api/v2/settings/app-links', ['links' => []], ['If-Match' => $etag])->assertStatus(412);

        $this->assertSame('[{"Legacy":{"link":"\/l","user_link":"","hide":false}}]', $this->sharedGet('APP_LINKS'));
    }
}
