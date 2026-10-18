<?php

namespace Tests\Feature\SecondFactors;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\Support\Totp\TestTotp;
use Tests\Support\WebAuthn\VirtualAuthenticator;
use Tests\TestCase;

/**
 * T041 (feature 005 FR-016, FR-017, US2 AS-7): a session restricted by a forced second-factor
 * setup enrols a second factor without the current password, and the restriction lifts at once.
 */
class ForcedSecondFactorSetupTest extends TestCase
{
    use SelfServiceTestHelpers;

    private const HOST = 'https://mail.example.org';

    private function restrictedAdministrator(): void
    {
        $this->mailcow()->admin('admin', MailcowFactory::PASSWORD, attributes: ['force_tfa' => '1', 'force_pw_update' => '0']);
        $this->signInAs('admin', Role::Admin);
        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'restricted')->assertJsonPath('data.pendingActions', ['secondFactorSetup']);
    }

    public function test_a_totp_enrolment_without_the_current_password_lifts_the_restriction(): void
    {
        $this->restrictedAdministrator();

        $secret = (string) $this->postEmpty('/api/v2/me/second-factors/totp-enrolment', $this->csrf())->assertOk()->json('data.secret');
        $this->postJson('/api/v2/me/second-factors', ['type' => 'totp', 'name' => 'Phone', 'code' => TestTotp::code($secret)], $this->csrf())->assertCreated();

        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'active');
        $this->assertTrue($this->onlyAuditRecord('ownSecondFactorEnrolment')->details['restrictionLifted']);

        // The next sign-in is not restricted either.
        $this->forgetCookies();
        $this->passwordSignIn('admin')->assertCreated()->assertJsonPath('data.state', 'pendingSecondFactor');
    }

    public function test_webauthn_options_and_enrolment_work_in_the_restricted_session(): void
    {
        $this->restrictedAdministrator();
        $authenticator = new VirtualAuthenticator;

        $options = $this->postEmpty(self::HOST.'/api/v2/me/second-factors/webauthn-registration-options', $this->csrf())->assertOk()->json('data.publicKey');
        $this->postJson(self::HOST.'/api/v2/me/second-factors', ['type' => 'webauthn', 'name' => 'Key', 'credential' => $authenticator->attest($options, self::HOST)], $this->csrf())
            ->assertCreated();

        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'active');
    }

    public function test_removals_stay_refused_while_restricted(): void
    {
        $this->mailcow()->mailboxWithDomain('bob@example.org', ['force_tfa' => '1', 'force_pw_update' => '1']);
        $id = $this->mailcow()->tfaTotp('bob@example.org', 'JBSWY3DPEHPK3PXP');
        $this->signInAs('bob@example.org', Role::MailboxUser);

        $this->deleteJson('/api/v2/me/second-factors/'.$id, [], $this->csrf())
            ->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/pending-action-required');
        $this->postJson('/api/v2/me/second-factors/removal', ['currentPassword' => MailcowFactory::PASSWORD], $this->csrf())
            ->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/pending-action-required');
        $this->getJson('/api/v2/me/second-factors/'.$id)->assertStatus(403);
        $this->assertSame(1, DB::table('tfa')->count());
    }

    public function test_with_both_actions_pending_the_enrolment_lifts_only_the_setup(): void
    {
        $this->asSelfServiceUser('bob@example.org', ['force_tfa' => '1', 'force_pw_update' => '1']);

        $secret = (string) $this->postEmpty('/api/v2/me/second-factors/totp-enrolment', $this->csrf())->assertOk()->json('data.secret');
        $this->postJson('/api/v2/me/second-factors', ['type' => 'totp', 'name' => 'Phone', 'code' => TestTotp::code($secret)], $this->csrf())->assertCreated();

        $this->getJson('/api/v2/session')->assertJsonPath('data.pendingActions', ['passwordChange']);
    }
}
