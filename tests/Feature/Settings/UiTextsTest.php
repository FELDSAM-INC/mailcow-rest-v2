<?php

namespace Tests\Feature\Settings;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Settings\SettingsTestHelpers;
use Tests\TestCase;

/**
 * Feature 013 T027 (FR-019 to FR-023, AS-1.5, AS-1.6): the UI texts in legacy's keys and formats:
 * the page title, product name and apps heading stored HTML-escaped as legacy's `htmlspecialchars()`
 * and returned as plain text, the help text and footer stored as given, unsent members kept, the
 * footer key of older releases migrated by the next change.
 */
class UiTextsTest extends TestCase
{
    use SettingsTestHelpers;

    private const KEYS = ['TITLE_NAME', 'MAIN_NAME', 'APPS_NAME', 'HELP_TEXT', 'UI_FOOTER', 'UI_IMPRESS', 'UI_ANNOUNCEMENT_TEXT', 'UI_ANNOUNCEMENT_TYPE', 'UI_ANNOUNCEMENT_ACTIVE'];

    /**
     * @return array<string, string|null>
     */
    private function stored(): array
    {
        $values = [];

        foreach (self::KEYS as $key) {
            $values[$key] = $this->sharedGet($key);
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $patch
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function patchTexts(array $patch, array $headers = []): TestResponse
    {
        return $this->send('PATCH', '/api/v2/settings/ui-texts', $patch, $headers);
    }

    public function test_a_fresh_server_reports_empty_texts_and_the_deployment_defaults(): void
    {
        $this->asAdmin();

        $response = $this->getJson('/api/v2/settings/ui-texts')->assertOk();

        $this->assertSame([
            'pageTitle' => '',
            'pageTitleDefault' => 'mail.example.org - mail UI',
            'productName' => '',
            'productNameDefault' => 'mail.example.org - mail UI',
            'appsHeading' => '',
            'loginHelpHtml' => '',
            'footerHtml' => '',
            'announcement' => ['text' => '', 'type' => null, 'active' => false],
        ], array_diff_key((array) $response->json('data'), ['etag' => true]));
        $response->assertHeader('ETag', (string) $response->json('data.etag'));
    }

    public function test_the_product_name_is_stored_escaped_and_returned_as_plain_text(): void
    {
        $this->asAdmin();

        $this->patchTexts(['productName' => 'Acme & Co Mail'])->assertOk()->assertJsonPath('data.productName', 'Acme & Co Mail');

        $this->assertSame(htmlspecialchars('Acme & Co Mail'), $this->sharedGet('MAIN_NAME'));
        $this->assertSame('Acme &amp; Co Mail', $this->sharedGet('MAIN_NAME'));
        $this->forgetCookies();
        $this->getJson('/api/v2/public-config')->assertOk()->assertJsonPath('data.productName', 'Acme & Co Mail');
    }

    public function test_markup_in_plain_texts_is_stored_escaped_and_html_fields_are_stored_as_given(): void
    {
        $this->asAdmin();

        $this->patchTexts([
            'pageTitle' => '<b>Mail</b> "quoted" \'single\'',
            'appsHeading' => 'Apps & more',
            'loginHelpHtml' => '<p>Call <a href="tel:123">us</a></p>',
            'footerHtml' => '<small>&copy; Acme</small>',
        ])->assertOk()
            ->assertJsonPath('data.pageTitle', '<b>Mail</b> "quoted" \'single\'')
            ->assertJsonPath('data.loginHelpHtml', '<p>Call <a href="tel:123">us</a></p>');

        $stored = $this->stored();
        $this->assertSame(htmlspecialchars('<b>Mail</b> "quoted" \'single\''), $stored['TITLE_NAME']);
        $this->assertSame('&lt;b&gt;Mail&lt;/b&gt; &quot;quoted&quot; &#039;single&#039;', $stored['TITLE_NAME']);
        $this->assertSame('Apps &amp; more', $stored['APPS_NAME']);
        $this->assertSame('<p>Call <a href="tel:123">us</a></p>', $stored['HELP_TEXT']);
        $this->assertSame('<small>&copy; Acme</small>', $stored['UI_FOOTER']);
    }

    public function test_the_announcement_is_stored_as_legacy_stores_it_and_deactivation_reaches_the_public_configuration(): void
    {
        $this->asAdmin();

        $this->patchTexts(['announcement' => ['text' => 'Maintenance <tonight>', 'type' => 'warning', 'active' => true]])->assertOk();
        $this->assertSame(['Maintenance <tonight>', 'warning', '1'], [$this->sharedGet('UI_ANNOUNCEMENT_TEXT'), $this->sharedGet('UI_ANNOUNCEMENT_TYPE'), $this->sharedGet('UI_ANNOUNCEMENT_ACTIVE')]);

        $this->patchTexts(['announcement' => ['active' => false]])->assertOk()->assertJsonPath('data.announcement.text', 'Maintenance <tonight>')->assertJsonPath('data.announcement.type', 'warning');
        $this->assertSame('0', $this->sharedGet('UI_ANNOUNCEMENT_ACTIVE'));

        $this->patchTexts(['announcement' => ['type' => null]])->assertOk()->assertJsonPath('data.announcement.type', null);
        $this->assertSame('', $this->sharedGet('UI_ANNOUNCEMENT_TYPE'));
        $this->forgetCookies();
        $this->getJson('/api/v2/public-config')->assertOk()->assertJsonPath('data.announcement.active', false)->assertJsonPath('data.announcement.type', null);
    }

    public function test_unsent_members_keep_their_values(): void
    {
        $this->asAdmin();
        $this->mailcow()->redisLegacy(['TITLE_NAME' => 'Old &amp; title', 'HELP_TEXT' => '<i>help</i>', 'UI_ANNOUNCEMENT_TEXT' => 'Hi', 'UI_ANNOUNCEMENT_ACTIVE' => '1']);

        $this->patchTexts(['productName' => 'New'])->assertOk()
            ->assertJsonPath('data.pageTitle', 'Old & title')
            ->assertJsonPath('data.loginHelpHtml', '<i>help</i>')
            ->assertJsonPath('data.announcement.active', true);

        $stored = $this->stored();
        $this->assertSame(['Old &amp; title', 'New', '<i>help</i>', 'Hi', '1'], [$stored['TITLE_NAME'], $stored['MAIN_NAME'], $stored['HELP_TEXT'], $stored['UI_ANNOUNCEMENT_TEXT'], $stored['UI_ANNOUNCEMENT_ACTIVE']]);
    }

    public function test_an_empty_title_means_the_deployment_default(): void
    {
        $this->asAdmin();
        $this->mailcow()->redisLegacy(['TITLE_NAME' => 'Acme']);

        $this->patchTexts(['pageTitle' => ''])->assertOk()->assertJsonPath('data.pageTitle', '')->assertJsonPath('data.pageTitleDefault', 'mail.example.org - mail UI');

        $this->assertSame('', $this->sharedGet('TITLE_NAME'));
        $this->forgetCookies();
        $this->getJson('/api/v2/public-config')->assertOk()->assertJsonPath('data.pageTitle', 'mail.example.org - mail UI');
    }

    public function test_invalid_values_are_refused_and_nothing_changes(): void
    {
        $this->asAdmin();
        $this->withoutRequestValidation();
        $this->mailcow()->redisLegacy(['MAIN_NAME' => 'Kept']);
        $before = $this->stored();

        $this->patchTexts(['productName' => str_repeat('a', 256)])->assertStatus(422)->assertJsonPath('errors.productName.0.code', 'tooLong');
        $this->patchTexts(['pageTitle' => "two\nlines"])->assertStatus(422)->assertJsonPath('errors.pageTitle.0.params.format', 'singleLine');
        $this->patchTexts(['appsHeading' => "tab\there"])->assertStatus(422)->assertJsonPath('errors.appsHeading.0.code', 'invalidFormat');
        $this->patchTexts(['footerHtml' => str_repeat('x', 65536)])->assertStatus(422)->assertJsonPath('errors.footerHtml.0.code', 'tooLong');
        $this->assertSame('notInList', $this->patchTexts(['announcement' => ['type' => 'critical']])->assertStatus(422)->json('errors')['announcement.type'][0]['code'] ?? null);
        $this->patchTexts(['productName' => 'Valid', 'announcement' => ['text' => str_repeat('x', 65536)]])->assertStatus(422);
        $this->patchTexts([])->assertStatus(422)->assertJsonPath('errors.body.0.code', 'required');
        $this->patchTexts(['unknown' => 'x'])->assertStatus(422)->assertJsonPath('errors.unknown.0.code', 'unknownField');

        $this->assertSame($before, $this->stored());
        $this->assertSame([], $this->successesOf('uiTextsUpdate'));
    }

    public function test_a_legacy_footer_is_read_and_moved_by_the_next_change(): void
    {
        $this->asAdmin();
        $this->mailcow()->redisLegacy(['UI_IMPRESS' => '<p>Imprint</p>']);

        $this->getJson('/api/v2/settings/ui-texts')->assertOk()->assertJsonPath('data.footerHtml', '<p>Imprint</p>');
        $this->assertSame('<p>Imprint</p>', $this->sharedGet('UI_IMPRESS'), 'Reading changes nothing.');

        $this->patchTexts(['appsHeading' => 'Apps'])->assertOk()->assertJsonPath('data.footerHtml', '<p>Imprint</p>');

        $this->assertSame('<p>Imprint</p>', $this->sharedGet('UI_FOOTER'));
        $this->assertNull($this->sharedGet('UI_IMPRESS'));
        $this->assertTrue($this->successesOf('uiTextsUpdate')[0]->details['footerMigrated']);
    }

    public function test_a_sent_footer_replaces_the_legacy_one(): void
    {
        $this->asAdmin();
        $this->mailcow()->redisLegacy(['UI_IMPRESS' => '<p>Imprint</p>']);

        $this->patchTexts(['footerHtml' => '<p>New</p>'])->assertOk()->assertJsonPath('data.footerHtml', '<p>New</p>');

        $this->assertSame('<p>New</p>', $this->sharedGet('UI_FOOTER'));
        $this->assertNull($this->sharedGet('UI_IMPRESS'));
    }

    public function test_an_outdated_tag_is_refused_also_after_a_legacy_change(): void
    {
        $this->asAdmin();
        $etag = (string) $this->getJson('/api/v2/settings/ui-texts')->assertOk()->json('data.etag');

        $this->mailcow()->redisLegacy(['HELP_TEXT' => 'changed by legacy']);

        $this->patchTexts(['productName' => 'Mine'], ['If-Match' => $etag])->assertStatus(412);
        $this->assertNull($this->sharedGet('MAIN_NAME'));

        $current = (string) $this->getJson('/api/v2/settings/ui-texts')->assertOk()->json('data.etag');
        $this->assertNotSame($etag, $current);
        $this->patchTexts(['productName' => 'Mine'], ['If-Match' => $current])->assertOk();
    }

    public function test_demo_mode_refuses_changes(): void
    {
        $this->asAdmin();
        config(['restv2.demo_mode' => true]);

        $this->patchTexts(['productName' => 'X'])->assertForbidden()->assertJsonPath('type', '/api/v2/problems/demo-mode');

        $this->assertNull($this->sharedGet('MAIN_NAME'));
    }

    public function test_a_change_is_audited_with_plain_values_and_html_sizes(): void
    {
        $this->asAdmin();
        $this->mailcow()->redisLegacy(['MAIN_NAME' => 'Old', 'UI_FOOTER' => '12345']);

        $this->patchTexts(['productName' => 'New', 'footerHtml' => '1234567', 'announcement' => ['text' => 'abc', 'active' => true]])->assertOk();

        $records = $this->successesOf('uiTextsUpdate');
        $this->assertCount(1, $records);
        $this->assertSame(['type' => 'uiTexts', 'id' => null], ['type' => $records[0]->target_type, 'id' => $records[0]->target_id]);
        $changes = $records[0]->details['changes'];
        $this->assertSame(['old' => 'Old', 'new' => 'New'], $changes['productName']);
        $this->assertSame(['changed' => true, 'oldBytes' => 5, 'newBytes' => 7], $changes['footerHtml']);
        $this->assertSame(['changed' => true, 'oldBytes' => 0, 'newBytes' => 3], $changes['announcement.text']);
        $this->assertSame(['old' => false, 'new' => true], $changes['announcement.active']);
        $this->assertFalse($records[0]->details['footerMigrated']);
    }

    public function test_a_change_that_changes_nothing_is_not_audited(): void
    {
        $this->asAdmin();
        $this->mailcow()->redisLegacy(['MAIN_NAME' => 'Same']);

        $this->patchTexts(['productName' => 'Same'])->assertOk();

        $this->assertSame([], $this->auditOf('uiTextsUpdate'));
    }
}
