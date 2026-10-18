<?php

namespace Tests\Feature\Webmail;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T070 (feature 005 FR-067, FR-068, US5 AS-1, AS-4, AS-5, AS-7): createWebmailHandOff writes a
 * webmail-only legacy session for the requesting browser.
 */
class WebmailHandOffTest extends TestCase
{
    use ApiKeys;
    use SelfServiceTestHelpers;

    public function test_a_mailbox_user_gets_a_webmail_only_legacy_session(): void
    {
        $this->asSelfServiceUser();

        [$response, $id] = $this->webmailHandOff();

        $response->assertOk()->assertExactJson(['data' => ['mailbox' => 'anna@example.org', 'url' => '/SOGo/so/']]);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertNotNull($id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        $header = collect($response->headers->all('set-cookie'))->first(fn (string $c): bool => str_starts_with($c, 'MCSESSID='));
        $this->assertNotNull($header);
        $this->assertStringContainsString('path=/', strtolower($header));
        $this->assertStringContainsString('secure', strtolower($header));
        $this->assertStringContainsString('httponly', strtolower($header));
        $this->assertStringContainsString('samesite=lax', strtolower($header));
        $this->assertStringNotContainsString('domain=', strtolower($header));
        $this->assertStringNotContainsString('expires=', strtolower($header));

        $session = $this->legacySession($id);
        $this->assertNotNull($session);
        $this->assertSame(['mailcow_cc_username', 'sogo-sso-user-allowed', 'mailcow_cc_api_access', 'SESS_REMOTE_UA', 'LAST_ACTIVITY', 'CSRF'], array_keys($session));
        $this->assertSame('anna@example.org', $session['mailcow_cc_username']);
        $this->assertSame(['anna@example.org'], $session['sogo-sso-user-allowed']);
        $this->assertSame('ro', $session['mailcow_cc_api_access']);
        $this->assertArrayNotHasKey('mailcow_cc_role', $session);
        $ttl = $this->redis()->ttl('PHPREDIS_SESSION:'.$id);
        $this->assertGreaterThan(7100, $ttl);
        $this->assertLessThanOrEqual(7200, $ttl);

        $this->assertTrue(DB::table('sasl_log')->where('username', 'anna@example.org')->where('service', 'SSO')->where('real_rip', '127.0.0.1')->exists());
        $record = $this->onlyAuditRecord('webmailHandOff');
        $this->assertSame(['success', 'webmailHandOff', 'anna@example.org'], [$record->outcome, $record->target_type, $record->target_id]);
        $this->assertStringNotContainsString($id, (string) json_encode($record));
    }

    public function test_the_cookie_follows_the_legacy_session_settings(): void
    {
        config(['restv2.vars_overrides.SESSION_NAME' => 'LEGACYSESS', 'restv2.vars_overrides.SESSION_SAMESITE_POLICY' => 'Strict']);
        $this->asSelfServiceUser();

        $response = $this->postEmpty('/api/v2/me/webmail-handoff', $this->csrf())->assertOk();

        $header = collect($response->headers->all('set-cookie'))->first(fn (string $c): bool => str_starts_with($c, 'LEGACYSESS='));
        $this->assertNotNull($header);
        $this->assertStringContainsString('samesite=strict', strtolower($header));
    }

    public function test_a_second_hand_off_replaces_the_first(): void
    {
        $this->asSelfServiceUser();

        [, $first] = $this->webmailHandOff();
        [, $second] = $this->webmailHandOff();

        $this->assertNotSame($first, $second);
        $this->assertNull($this->legacySession((string) $first));
        $this->assertNotNull($this->legacySession((string) $second));
    }

    public function test_refusals(): void
    {
        config(['restv2.skip_sogo' => true]);
        $this->asSelfServiceUser();
        $this->postEmpty('/api/v2/me/webmail-handoff', $this->csrf())->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/groupware-disabled');
        config(['restv2.skip_sogo' => false]);

        $this->asSelfServiceUser('bob@example.org', ['force_pw_update' => '1']);
        $this->postEmpty('/api/v2/me/webmail-handoff', $this->csrf())->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/pending-action-required');

        $this->asAdmin();
        $this->postEmpty('/api/v2/me/webmail-handoff', $this->csrf())->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');
        $this->asDomainAdmin(['example.org']);
        $this->postEmpty('/api/v2/me/webmail-handoff', $this->csrf())->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->forgetCookies();
        $this->postEmpty('/api/v2/me/webmail-handoff', $this->keyHeader($this->apiKey('admin')))->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/session-required');
        $this->assertSame('featureDisabled', $this->auditRecords('webmailHandOff')[0]->failure_reason);
    }

    public function test_without_the_shared_store_nothing_is_recorded(): void
    {
        $this->asSelfServiceUser();
        $headers = $this->csrf();
        $this->breakSharedStore();

        $this->postEmpty('/api/v2/me/webmail-handoff', $headers)
            ->assertStatus(503)
            ->assertJsonPath('type', '/api/v2/problems/temporary-failure')
            ->assertJsonPath('dependency', 'sharedStore');

        $this->assertFalse(DB::table('sasl_log')->where('service', 'SSO')->exists());
    }

    public function test_the_hand_off_is_audited_against_the_mailbox_of_a_role_that_has_one(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $this->signInAs('anna@example.org', Role::MailboxUser);

        $this->webmailHandOff();

        $this->assertSame('anna@example.org', $this->onlyAuditRecord('webmailHandOff')->actor_username);
    }
}
