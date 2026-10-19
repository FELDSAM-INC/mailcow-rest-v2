<?php

namespace Tests\Feature\Settings;

use Tests\Support\Settings\SettingsTestHelpers;
use Tests\TestCase;

/**
 * Feature 013 T041 (FR-039 to FR-042, AS-2.3 to AS-2.5): the password reset e-mail in legacy's
 * `PW_RESET_FROM`, `PW_RESET_SUBJ`, `PW_RESET_TEXT` and `PW_RESET_HTML`; mailcow's default templates
 * while both are empty; password reset available only with a sender and a subject.
 */
class PasswordResetNotificationTest extends TestCase
{
    use SettingsTestHelpers;

    private const URI = '/api/v2/settings/password-reset-notification';

    private static function defaultTemplate(string $file): string
    {
        return (string) file_get_contents(__DIR__.'/../../fixtures/mailcow-tpls/'.$file);
    }

    public function test_a_fresh_server_returns_the_default_templates_and_no_reset(): void
    {
        $this->asAdmin();

        $response = $this->getJson(self::URI)->assertOk();

        $this->assertSame([
            'sender' => '',
            'subject' => '',
            'textTemplate' => self::defaultTemplate('pw_reset_text.tpl'),
            'htmlTemplate' => self::defaultTemplate('pw_reset_html.tpl'),
            'templatesAreDefault' => true,
            'passwordResetAvailable' => false,
        ], array_diff_key((array) $response->json('data'), ['etag' => true]));
        $response->assertHeader('ETag', (string) $response->json('data.etag'));
    }

    public function test_valid_values_are_stored_where_legacy_keeps_them(): void
    {
        $this->asAdmin();

        $this->send('PATCH', self::URI, ['sender' => 'no-reply@example.org', 'subject' => 'Reset your password', 'textTemplate' => 'Use {{link}}', 'htmlTemplate' => '<a href="{{link}}">Reset</a>'])->assertOk()
            ->assertJsonPath('data.templatesAreDefault', false)
            ->assertJsonPath('data.passwordResetAvailable', true);

        $this->assertSame(['no-reply@example.org', 'Reset your password', 'Use {{link}}', '<a href="{{link}}">Reset</a>'], [$this->sharedGet('PW_RESET_FROM'), $this->sharedGet('PW_RESET_SUBJ'), $this->sharedGet('PW_RESET_TEXT'), $this->sharedGet('PW_RESET_HTML')]);
    }

    public function test_an_invalid_sender_is_refused_and_nothing_is_stored(): void
    {
        $this->asAdmin();
        $this->withoutRequestValidation();
        $this->sharedValue('PW_RESET_FROM', 'kept@example.org');

        $this->send('PATCH', self::URI, ['sender' => 'no-reply@'])->assertStatus(422)->assertJsonPath('errors.sender.0.params.format', 'email');
        $this->send('PATCH', self::URI, ['subject' => "two\nlines"])->assertStatus(422)->assertJsonPath('errors.subject.0.params.format', 'singleLine');
        $this->send('PATCH', self::URI, ['subject' => str_repeat('s', 256)])->assertStatus(422)->assertJsonPath('errors.subject.0.code', 'tooLong');
        $this->send('PATCH', self::URI, ['htmlTemplate' => str_repeat('x', 1048577)])->assertStatus(422)
            ->assertJsonPath('errors.htmlTemplate.0.code', 'tooLong')
            ->assertJsonPath('errors.htmlTemplate.0.params', ['max' => 1048576, 'unit' => 'bytes']);

        $this->assertSame('kept@example.org', $this->sharedGet('PW_RESET_FROM'));
        $this->assertNull($this->sharedGet('PW_RESET_HTML'));
    }

    public function test_both_templates_empty_restore_the_defaults_and_an_empty_subject_switches_reset_off(): void
    {
        $this->asAdmin();
        $this->mailcow()->redisLegacy(['PW_RESET_FROM' => 'a@example.org', 'PW_RESET_SUBJ' => 'S', 'PW_RESET_TEXT' => 'custom', 'PW_RESET_HTML' => '']);

        $this->getJson(self::URI)->assertOk()->assertJsonPath('data.textTemplate', 'custom')->assertJsonPath('data.htmlTemplate', '')->assertJsonPath('data.templatesAreDefault', false);

        $this->send('PATCH', self::URI, ['textTemplate' => '', 'subject' => ''])->assertOk()
            ->assertJsonPath('data.templatesAreDefault', true)
            ->assertJsonPath('data.textTemplate', self::defaultTemplate('pw_reset_text.tpl'))
            ->assertJsonPath('data.passwordResetAvailable', false);
        $this->assertSame(['', ''], [$this->sharedGet('PW_RESET_TEXT'), $this->sharedGet('PW_RESET_SUBJ')]);
    }

    public function test_a_template_without_the_link_is_accepted(): void
    {
        $this->asAdmin();

        $this->send('PATCH', self::URI, ['textTemplate' => 'No link here'])->assertOk()->assertJsonPath('data.textTemplate', 'No link here');
    }

    public function test_the_tag_changes_after_a_legacy_write(): void
    {
        $this->asAdmin();
        $etag = (string) $this->getJson(self::URI)->json('data.etag');
        $this->sharedValue('PW_RESET_SUBJ', 'changed by legacy');

        $this->assertNotSame($etag, $this->getJson(self::URI)->json('data.etag'));
        $this->send('PATCH', self::URI, ['sender' => 'a@example.org'], ['If-Match' => $etag])->assertStatus(412);
    }

    public function test_a_change_is_audited_with_template_sizes_only(): void
    {
        $this->asAdmin();

        $this->send('PATCH', self::URI, ['sender' => 'a@example.org', 'htmlTemplate' => '<p>secret template</p>'])->assertOk();

        $records = $this->successesOf('passwordResetNotificationUpdate');
        $this->assertCount(1, $records);
        $this->assertSame(['old' => '', 'new' => 'a@example.org'], $records[0]->details['changes']['sender']);
        $this->assertSame(['changed' => true, 'oldBytes' => 0, 'newBytes' => 22], $records[0]->details['changes']['htmlTemplate']);
        $this->assertStringNotContainsString('secret template', json_encode($records) ?: '');
    }

    public function test_domain_administrators_are_forbidden(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asDomainAdmin(['example.org']);

        $this->getJson(self::URI)->assertForbidden();
        $this->send('PATCH', self::URI, ['subject' => 'x'])->assertForbidden();
    }
}
