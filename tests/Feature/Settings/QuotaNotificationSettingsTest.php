<?php

namespace Tests\Feature\Settings;

use Tests\Support\Settings\SettingsTestHelpers;
use Tests\TestCase;

/**
 * Feature 013 T061 (FR-043 to FR-045, AS-5.1, AS-5.2): the quota notification e-mail in legacy's
 * `QW_SENDER`, `QW_SUBJ` and `QW_HTML`; empty values mean Dovecot's defaults, which the read
 * returns and marks.
 */
class QuotaNotificationSettingsTest extends TestCase
{
    use SettingsTestHelpers;

    private const URI = '/api/v2/settings/quota-notification';

    public function test_a_fresh_server_returns_dovecot_s_defaults(): void
    {
        $this->asAdmin();

        $response = $this->getJson(self::URI)->assertOk();

        $this->assertSame([
            'sender' => 'quota-warning@localhost',
            'senderIsDefault' => true,
            'subject' => 'Quota warning',
            'subjectIsDefault' => true,
            'htmlTemplate' => (string) file_get_contents(base_path('tests/fixtures/mailcow-tpls/quota.tpl')),
            'htmlTemplateIsDefault' => true,
        ], array_diff_key((array) $response->json('data'), ['etag' => true]));
        $response->assertHeader('ETag', (string) $response->json('data.etag'));
    }

    public function test_values_are_stored_where_dovecot_reads_them(): void
    {
        $this->asAdmin();
        $template = '<p>{{username}} is at {{percent}}%</p>';

        $this->send('PATCH', self::URI, ['sender' => 'quota@example.org', 'subject' => 'Your mailbox is almost full', 'htmlTemplate' => $template])->assertOk()
            ->assertJsonPath('data.sender', 'quota@example.org')
            ->assertJsonPath('data.senderIsDefault', false)
            ->assertJsonPath('data.subjectIsDefault', false)
            ->assertJsonPath('data.htmlTemplate', $template)
            ->assertJsonPath('data.htmlTemplateIsDefault', false);

        $this->assertSame(['quota@example.org', 'Your mailbox is almost full', $template], [$this->sharedGet('QW_SENDER'), $this->sharedGet('QW_SUBJ'), $this->sharedGet('QW_HTML')]);
    }

    public function test_an_empty_value_restores_the_default(): void
    {
        $this->asAdmin();
        $this->mailcow()->redisLegacy(['QW_SENDER' => 'quota@example.org', 'QW_SUBJ' => 'Full']);

        $this->send('PATCH', self::URI, ['sender' => ''])->assertOk()
            ->assertJsonPath('data.sender', 'quota-warning@localhost')
            ->assertJsonPath('data.senderIsDefault', true)
            ->assertJsonPath('data.subject', 'Full');

        $this->assertSame('', $this->sharedGet('QW_SENDER'));
    }

    public function test_invalid_values_are_refused_and_nothing_changes(): void
    {
        $this->asAdmin();
        $this->withoutRequestValidation();
        $this->sharedValue('QW_SENDER', 'kept@example.org');

        $this->send('PATCH', self::URI, ['sender' => 'not an address'])->assertStatus(422)->assertJsonPath('errors.sender.0.params.format', 'email');
        $this->send('PATCH', self::URI, ['subject' => "two\nlines"])->assertStatus(422)->assertJsonPath('errors.subject.0.params.format', 'singleLine');
        $this->send('PATCH', self::URI, ['htmlTemplate' => str_repeat('x', 1048577)])->assertStatus(422)->assertJsonPath('errors.htmlTemplate.0.params.unit', 'bytes');
        $this->send('PATCH', self::URI, ['retentionSize' => 1])->assertStatus(422)->assertJsonPath('errors.retentionSize.0.code', 'unknownField');

        $this->assertSame('kept@example.org', $this->sharedGet('QW_SENDER'));
    }

    public function test_a_template_with_a_syntax_error_is_accepted(): void
    {
        $this->asAdmin();

        $this->send('PATCH', self::URI, ['htmlTemplate' => '{% if %}'])->assertOk()->assertJsonPath('data.htmlTemplate', '{% if %}');
    }

    public function test_changes_are_audited_with_template_sizes_and_other_roles_are_forbidden(): void
    {
        $this->asAdmin();
        $this->send('PATCH', self::URI, ['subject' => 'Full', 'htmlTemplate' => '<p>x</p>'])->assertOk();

        $records = $this->successesOf('quotaNotificationUpdate');
        $this->assertCount(1, $records);
        $this->assertSame(['old' => '', 'new' => 'Full'], $records[0]->details['changes']['subject']);
        $this->assertSame(['changed' => true, 'oldBytes' => 0, 'newBytes' => 8], $records[0]->details['changes']['htmlTemplate']);

        $this->forgetCookies();
        $this->mailcow()->domain('example.org');
        $this->asDomainAdmin(['example.org']);
        $this->getJson(self::URI)->assertForbidden();
        $this->send('PATCH', self::URI, ['subject' => 'x'])->assertForbidden();
    }
}
