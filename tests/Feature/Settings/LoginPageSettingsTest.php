<?php

namespace Tests\Feature\Settings;

use Tests\Support\Mailcow\MailcowFactory;
use Tests\Support\Settings\SettingsTestHelpers;
use Tests\TestCase;

/**
 * Feature 013 T028 (FR-024): the login page options in legacy's `CUSTOM_LOGIN` JSON, written
 * byte-identical to legacy's `json_encode()`; unsent options keep their values; forcing single
 * sign-on is stored without an identity provider and never refuses a password sign-in.
 */
class LoginPageSettingsTest extends TestCase
{
    use SettingsTestHelpers;

    public function test_a_fresh_server_shows_every_login_link(): void
    {
        $this->asAdmin();

        $response = $this->getJson('/api/v2/settings/login-page')->assertOk();

        $this->assertSame(['hideAdminLogin' => false, 'hideDomainAdminLogin' => false, 'hideMailboxUserLogin' => false, 'forceSingleSignOn' => false], array_diff_key((array) $response->json('data'), ['etag' => true]));
        $response->assertHeader('ETag', (string) $response->json('data.etag'));
    }

    public function test_a_change_is_written_exactly_as_legacy_writes_it(): void
    {
        $this->asAdmin();

        $this->send('PATCH', '/api/v2/settings/login-page', ['hideAdminLogin' => true, 'forceSingleSignOn' => true])->assertOk()
            ->assertJsonPath('data.hideAdminLogin', true)
            ->assertJsonPath('data.hideMailboxUserLogin', false)
            ->assertJsonPath('data.forceSingleSignOn', true);

        $legacy = json_encode(['hide_user_quicklink' => 0, 'hide_domainadmin_quicklink' => 0, 'hide_admin_quicklink' => 1, 'force_sso' => 1]);
        $this->assertSame($legacy, $this->sharedGet('CUSTOM_LOGIN'));
        $this->assertSame('{"hide_user_quicklink":0,"hide_domainadmin_quicklink":0,"hide_admin_quicklink":1,"force_sso":1}', $this->sharedGet('CUSTOM_LOGIN'));
    }

    public function test_unsent_options_keep_their_values_and_the_public_configuration_follows(): void
    {
        $this->asAdmin();
        $this->sharedValue('CUSTOM_LOGIN', '{"hide_user_quicklink":1,"hide_domainadmin_quicklink":0,"hide_admin_quicklink":0,"force_sso":0}');

        $this->send('PATCH', '/api/v2/settings/login-page', ['hideDomainAdminLogin' => true])->assertOk()
            ->assertJsonPath('data.hideMailboxUserLogin', true)
            ->assertJsonPath('data.hideDomainAdminLogin', true);

        $this->forgetCookies();
        $this->getJson('/api/v2/public-config')->assertOk()
            ->assertJsonPath('data.loginPage.hideMailboxUserLogin', true)
            ->assertJsonPath('data.loginPage.hideDomainAdminLogin', true)
            ->assertJsonPath('data.loginPage.hideAdminLogin', false);
    }

    public function test_forcing_single_sign_on_never_refuses_a_password_sign_in(): void
    {
        $this->asAdmin();
        $this->send('PATCH', '/api/v2/settings/login-page', ['forceSingleSignOn' => true])->assertOk();
        $this->mailcow()->mailboxWithDomain('user@example.org');
        $this->forgetCookies();

        $token = $this->csrfToken();
        $this->postJson('/api/v2/session', ['username' => 'user@example.org', 'password' => MailcowFactory::PASSWORD], ['X-CSRF-Token' => $token])->assertCreated();
    }

    public function test_invalid_values_and_an_outdated_tag_change_nothing(): void
    {
        $this->asAdmin();
        $this->withoutRequestValidation();
        $etag = (string) $this->getJson('/api/v2/settings/login-page')->json('data.etag');
        $this->sharedValue('CUSTOM_LOGIN', '{"hide_user_quicklink":0,"hide_domainadmin_quicklink":0,"hide_admin_quicklink":0,"force_sso":1}');

        $this->send('PATCH', '/api/v2/settings/login-page', ['hideAdminLogin' => 'yes'])->assertStatus(422)->assertJsonPath('errors.hideAdminLogin.0.code', 'invalidType');
        $this->send('PATCH', '/api/v2/settings/login-page', ['hideAdminLogin' => true], ['If-Match' => $etag])->assertStatus(412);

        $this->assertSame('{"hide_user_quicklink":0,"hide_domainadmin_quicklink":0,"hide_admin_quicklink":0,"force_sso":1}', $this->sharedGet('CUSTOM_LOGIN'));
    }

    public function test_demo_mode_refuses_changes_and_changes_are_audited(): void
    {
        $this->asAdmin();
        config(['restv2.demo_mode' => true]);
        $this->send('PATCH', '/api/v2/settings/login-page', ['hideAdminLogin' => true])->assertForbidden()->assertJsonPath('type', '/api/v2/problems/demo-mode');
        $this->assertNull($this->sharedGet('CUSTOM_LOGIN'));

        config(['restv2.demo_mode' => false]);
        $this->send('PATCH', '/api/v2/settings/login-page', ['hideAdminLogin' => true])->assertOk();

        $records = $this->successesOf('loginPageUpdate');
        $this->assertCount(1, $records);
        $this->assertSame(['hideAdminLogin' => ['old' => false, 'new' => true]], $records[0]->details['changes']);
        $this->assertSame('loginPage', $records[0]->target_type);
    }
}
