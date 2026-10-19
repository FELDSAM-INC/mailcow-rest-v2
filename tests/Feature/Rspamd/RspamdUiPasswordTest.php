<?php

namespace Tests\Feature\Rspamd;

use App\Clients\DockerApi\DockerCallResult;
use Illuminate\Support\Facades\DB;
use Tests\Support\Settings\SettingsTestHelpers;
use Tests\TestCase;

/**
 * Feature 013 T080 (FR-006, FR-066, FR-067, AS-7.1, AS-7.2, SC-012): a signed-in administrator sets
 * the Rspamd web interface password through mailcow's container service, as legacy does; the
 * password never appears in a response, the audit log or the log.
 */
class RspamdUiPasswordTest extends TestCase
{
    use SettingsTestHelpers;

    private const URI = '/api/v2/settings/rspamd-ui-password';

    private const PASSPHRASE = 'Moo "cow" pass 1';

    public function test_the_password_is_handed_to_the_container_service_exactly_as_given(): void
    {
        $this->asAdmin();

        $response = $this->send('PUT', self::URI, ['password' => self::PASSPHRASE])->assertNoContent();

        $this->dockerApi()->assertExecuted('rspamd-mailcow', ['cmd' => 'rspamd', 'task' => 'worker_password', 'raw' => self::PASSPHRASE]);
        $this->assertStringNotContainsString(self::PASSPHRASE, (string) $response->getContent());
        $record = $this->successesOf('rspamdUiPasswordChange');
        $this->assertCount(1, $record);
        $this->assertSame(['passwordChanged' => true, 'rspamdConfirmed' => true], $record[0]->details);
        $this->assertSame('rspamdUiPassword', $record[0]->target_type);
        $this->assertStringNotContainsString('Moo', json_encode(DB::table('restv2_audit_events')->get(), JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function test_invalid_passwords_are_refused_without_a_call(): void
    {
        $this->asAdmin();
        $this->withoutRequestValidation();

        $this->send('PUT', self::URI, ['password' => '12345'])->assertStatus(422)->assertJsonPath('errors.password.0.code', 'tooShort')->assertJsonPath('errors.password.0.params.min', 6);
        $this->send('PUT', self::URI, ['password' => str_repeat('p', 257)])->assertStatus(422)->assertJsonPath('errors.password.0.code', 'tooLong');
        $this->send('PUT', self::URI, ['password' => "secret\nline"])->assertStatus(422)->assertJsonPath('errors.password.0.params.format', 'singleLine');
        $this->send('PUT', self::URI, [])->assertStatus(422)->assertJsonPath('errors.password.0.code', 'required');

        $this->dockerApi()->assertNoAction();
    }

    public function test_a_service_failure_is_reported_without_its_message(): void
    {
        $this->asAdmin();
        $this->dockerApi()->respondTo('exec', DockerCallResult::failed('rspamadm failed for '.self::PASSPHRASE));

        $response = $this->send('PUT', self::URI, ['password' => self::PASSPHRASE])->assertStatus(502)
            ->assertJsonPath('type', '/api/v2/problems/container-action-failed')
            ->assertJsonPath('container', 'rspamd-mailcow')
            ->assertJsonMissingPath('serviceMessage');

        $this->assertStringNotContainsString(self::PASSPHRASE, (string) $response->getContent());
        $this->assertStringContainsString('next start', (string) $response->json('detail'));
        $refusal = $this->refusalsOf('rspamdUiPasswordChange');
        $this->assertSame(['serviceFailure'], array_column($refusal, 'failure_reason'));
        $this->assertStringNotContainsString('Moo', json_encode($refusal, JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function test_a_timeout_is_an_unknown_outcome(): void
    {
        $this->asAdmin();
        $this->dockerApi()->failNext('exec', 'timeout');

        $this->send('PUT', self::URI, ['password' => self::PASSPHRASE])->assertStatus(504)
            ->assertJsonPath('type', '/api/v2/problems/outcome-unknown')
            ->assertJsonPath('step', 'rspamdUiPassword');

        $this->assertSame(['outcomeUnknown'], array_column($this->refusalsOf('rspamdUiPasswordChange'), 'failure_reason'));
    }

    public function test_api_keys_are_refused_with_session_required_and_nothing_is_called(): void
    {
        $this->send('PUT', self::URI, ['password' => self::PASSPHRASE], $this->adminKey())->assertForbidden()->assertJsonPath('type', '/api/v2/problems/session-required');
        $this->send('PUT', self::URI, ['password' => self::PASSPHRASE], $this->adminKey('readOnly'))->assertForbidden();

        $this->dockerApi()->assertNoAction();
    }

    public function test_domain_administrators_are_forbidden(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asDomainAdmin(['example.org']);

        $this->send('PUT', self::URI, ['password' => self::PASSPHRASE])->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->dockerApi()->assertNoAction();
    }

    public function test_the_password_never_reaches_the_log(): void
    {
        $log = storage_path('logs/testing.log');
        $before = is_file($log) ? (int) filesize($log) : 0;
        $this->asAdmin();
        $this->dockerApi()->respondTo('exec', DockerCallResult::failed('boom'));

        $this->send('PUT', self::URI, ['password' => self::PASSPHRASE])->assertStatus(502);
        $this->send('PUT', self::URI, ['password' => self::PASSPHRASE."\n"]);

        $written = is_file($log) ? (string) file_get_contents($log, false, null, $before) : '';
        $this->assertStringNotContainsString(self::PASSPHRASE, $written);
    }
}
