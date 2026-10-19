<?php

namespace Tests\Feature\Quarantine;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Quarantine\QuarantineTestHelpers;
use Tests\TestCase;

/**
 * Feature 006 T053 (FR-070, FR-071, AS-3.8 to AS-3.10): a notification template is saved only when
 * the legacy notifier can parse and render it; `validateOnly` checks without storing or auditing;
 * the preview renders the given, stored or default template with the sample data, as text.
 */
class QuarantineTemplateTest extends TestCase
{
    use QuarantineTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpamFilter();
        $this->mailcow()->domain('example.org');
    }

    /**
     * @phpstan-impure
     */
    private function auditCount(): int
    {
        return DB::table('restv2_audit_events')->count();
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2)."/fixtures/quarantine/templates/{$name}.tpl");
    }

    public function test_a_template_with_a_syntax_error_is_refused_with_the_line(): void
    {
        $this->asAdmin();

        $this->send('PATCH', '/api/v2/quarantine/settings', ['notificationTemplate' => self::fixture('unclosed-for')])
            ->assertStatus(422)
            ->assertJsonPath('errors.notificationTemplate.0.code', 'invalidTemplate')
            ->assertJsonPath('errors.notificationTemplate.0.params.reason', 'syntax')
            ->assertJsonPath('errors.notificationTemplate.0.params.line', 4);

        $this->assertFalse((bool) $this->redis()->exists('Q_HTML'));
        $record = DB::table('restv2_audit_events')->where('event_type', 'quarantineSettingsUpdate')->first();
        $this->assertNotNull($record);
        $this->assertSame('failure', $record->outcome);
        $this->assertSame('validationFailed', $record->failure_reason);
    }

    public function test_sandbox_violations_and_render_errors_are_refused(): void
    {
        $this->asAdmin();

        foreach (['unsafe-attribute' => 'security', 'sandbox-call' => 'security', 'zero-division' => 'render', 'undefined-attribute' => 'render', 'acl-branch-error' => 'render'] as $name => $reason) {
            $this->send('PATCH', '/api/v2/quarantine/settings', ['notificationTemplate' => self::fixture($name)])
                ->assertStatus(422)
                ->assertJsonPath('errors.notificationTemplate.0.code', 'invalidTemplate')
                ->assertJsonPath('errors.notificationTemplate.0.params.reason', $reason);
        }

        $this->assertFalse((bool) $this->redis()->exists('Q_HTML'));
    }

    public function test_a_valid_template_is_stored(): void
    {
        $this->asAdmin();

        $this->send('PATCH', '/api/v2/quarantine/settings', ['notificationTemplate' => self::fixture('valid-simple')])
            ->assertOk()
            ->assertJsonPath('data.notificationTemplateIsDefault', false);

        $this->assertSame(self::fixture('valid-simple'), $this->redis()->get('Q_HTML'));
    }

    public function test_validate_only_checks_without_storing_or_auditing(): void
    {
        $this->asAdmin();

        $response = $this->send('PATCH', '/api/v2/quarantine/settings?validateOnly=true', ['notificationTemplate' => self::fixture('valid-simple'), 'maxAgeDays' => 30])
            ->assertOk()
            ->assertExactJson(['data' => ['valid' => true]]);

        $this->assertFalse($response->headers->has('ETag'));
        $this->assertFalse((bool) $this->redis()->exists('Q_HTML'));
        $this->assertFalse((bool) $this->redis()->exists('Q_MAX_AGE'));
        $this->assertSame(0, DB::table('restv2_audit_events')->count());

        $this->send('PATCH', '/api/v2/quarantine/settings?validateOnly=true', ['notificationTemplate' => self::fixture('unclosed-for')])
            ->assertStatus(422)
            ->assertJsonPath('errors.notificationTemplate.0.params.reason', 'syntax');
        $this->send('PATCH', '/api/v2/quarantine/settings?validateOnly=true', ['maxAgeDays' => 30], ['If-Match' => '"stale"'])->assertStatus(412);

        $this->assertSame(0, $this->auditCount());
    }

    public function test_the_preview_renders_the_default_template_with_the_sample_data(): void
    {
        $this->asAdmin();

        $data = (array) $this->send('POST', '/api/v2/quarantine/settings/template-preview')->assertOk()->json('data');

        $this->assertSame('Spam Quarantine Notification', $data['subject']);
        $this->assertStringContainsString('https://mail.example.org/qhandler/release/'.str_repeat('0', 64), $data['html']);
        $this->assertStringContainsString('https://mail.example.org/qhandler/delete/'.str_repeat('0', 64), $data['html']);
        $this->assertStringContainsString('Sample message 2', $data['text']);
        $this->assertStringNotContainsString('<td', $data['text']);
        $this->assertSame('user@example.org', $data['sample']['username']);
        $this->assertSame(3, $data['sample']['counter']);
        $this->assertSame('mail.example.org', $data['sample']['hostname']);
        $this->assertSame(1, $data['sample']['quarantineAcl']);
        $this->assertCount(3, $data['sample']['items']);
        $this->assertSame('2026-01-01 08:00:00', $data['sample']['items'][0]['created']);
        $this->assertSame(0, DB::table('restv2_audit_events')->count());
    }

    public function test_the_preview_without_quick_actions_has_no_links(): void
    {
        $this->asAdmin();
        $this->sharedValue('Q_SUBJ', 'Your quarantine');

        $data = (array) $this->send('POST', '/api/v2/quarantine/settings/template-preview', ['quarantineAcl' => false])->assertOk()->json('data');

        $this->assertStringNotContainsString('/qhandler/', $data['html']);
        $this->assertSame('Your quarantine', $data['subject']);
        $this->assertSame(0, $data['sample']['quarantineAcl']);
    }

    public function test_the_preview_of_a_given_or_stored_template(): void
    {
        $this->asAdmin();

        $this->send('POST', '/api/v2/quarantine/settings/template-preview', ['template' => 'Hi {{ username }}: {{ counter }}'])->assertOk()->assertJsonPath('data.html', 'Hi user@example.org: 3');
        $this->send('POST', '/api/v2/quarantine/settings/template-preview', ['template' => self::fixture('unknown-tag')])
            ->assertStatus(422)
            ->assertJsonPath('errors.template.0.code', 'invalidTemplate')
            ->assertJsonPath('errors.template.0.params.reason', 'syntax');

        $this->sharedValue('Q_HTML', '{% for x in meta %}');
        $this->send('POST', '/api/v2/quarantine/settings/template-preview')->assertStatus(422)->assertJsonPath('errors.template.0.code', 'invalidTemplate');
        $this->assertSame(0, DB::table('restv2_audit_events')->count());
    }

    public function test_only_administrators_preview(): void
    {
        $this->mailcow()->domainAdmin('reseller', ['example.org'], scheme: 'SSHA256');
        $this->signInAs('reseller', Role::DomainAdmin);
        $this->send('POST', '/api/v2/quarantine/settings/template-preview')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->forgetCookies();
        $this->mailcow()->admin('admin', 'Passw0rd!', 'SSHA256');
        $this->send('POST', '/api/v2/quarantine/settings/template-preview', null, $this->keyHeader($this->apiKey('admin', 'readOnly')))
            ->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');
        $this->assertSame(0, DB::table('restv2_audit_events')->count());
    }
}
