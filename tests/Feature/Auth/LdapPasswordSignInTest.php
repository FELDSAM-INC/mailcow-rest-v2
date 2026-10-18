<?php

namespace Tests\Feature\Auth;

use App\Support\Totp\NoQrCodeProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RobThree\Auth\TwoFactorAuth;
use Tests\Support\IdentityProvider\ProviderPasswordTestHelpers;
use Tests\TestCase;

/**
 * T066 (FR-029 to FR-031, US3 AS-1, AS-7, AS-8): password sign-in of LDAP mailboxes through feature
 * 001's sign-in, with its second factors, forced setup and recent-login entry.
 */
class LdapPasswordSignInTest extends TestCase
{
    use ProviderPasswordTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->externalMailbox('carl@example.org', 'ldap');
    }

    public function test_an_ldap_mailbox_signs_in_with_its_directory_password(): void
    {
        $ldap = $this->directory(['use_ssl' => '1', 'port' => '389']);

        $this->passwordSignIn('carl@example.org', self::DIRECTORY_PASSWORD)->assertCreated()
            ->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.authMethod', 'directoryPassword');

        $ldap->assertSearched(self::CARL_FILTER, 'ou=people,dc=example,dc=org');
        $ldap->assertBoundAs(self::CARL_DN);
        $this->assertStringContainsString('ldaps://ldap1.example.org:636', $ldap->calls()[0]['uris']);
        $this->assertSame(1, DB::table('sasl_log')->where('username', 'carl@example.org')->count());
        $this->getJson('/api/v2/me')->assertOk()
            ->assertJsonPath('data.authentication.method', 'directoryPassword')
            ->assertJsonPath('data.authentication.identityProvider', 'ldap');
        $audit = $this->signInAudit()[0];
        $this->assertSame(['success', 'directoryPassword', 'ldap'], [$audit->outcome, $audit->auth_method, $audit->details['identityProvider']]);
    }

    public function test_a_second_factor_follows_the_directory_password(): void
    {
        $this->directory();
        $id = $this->mailcow()->tfaTotp('carl@example.org', 'JBSWY3DPEHPK3PXP');

        $this->passwordSignIn('carl@example.org', self::DIRECTORY_PASSWORD)->assertCreated()->assertJsonPath('data.state', 'pendingSecondFactor');
        $code = (new TwoFactorAuth(new NoQrCodeProvider))->getCode('JBSWY3DPEHPK3PXP', CarbonImmutable::now()->getTimestamp());

        $this->verifySecondFactor(['type' => 'totp', 'methodId' => $id, 'code' => $code])->assertOk()
            ->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.authMethod', 'directoryPasswordAndSecondFactor');
    }

    public function test_second_factors_disabled_for_the_next_sign_in_are_re_activated(): void
    {
        $this->directory();
        $this->mailcow()->tfaTotp('carl@example.org', 'JBSWY3DPEHPK3PXP', active: 0);

        $this->passwordSignIn('carl@example.org', self::DIRECTORY_PASSWORD)->assertCreated()->assertJsonPath('data.state', 'active');

        $this->assertSame(1, (int) DB::table('tfa')->where('username', 'carl@example.org')->value('active'));
    }

    public function test_forced_second_factor_setup_restricts_the_session(): void
    {
        $this->directory();
        DB::update("UPDATE `mailbox` SET `attributes` = JSON_SET(`attributes`, '$.force_tfa', '1') WHERE `username` = 'carl@example.org'");

        $this->passwordSignIn('carl@example.org', self::DIRECTORY_PASSWORD)->assertCreated()
            ->assertJsonPath('data.state', 'restricted')
            ->assertJsonPath('data.pendingActions', ['secondFactorSetup']);
    }

    public function test_an_administrator_with_the_same_name_is_tried_first(): void
    {
        $ldap = $this->directory();
        $this->mailcow()->admin('carl@example.org', 'AdminPassw0rd!');

        $this->passwordSignIn('carl@example.org', 'AdminPassw0rd!')->assertCreated()->assertJsonPath('data.role', 'admin');
        $ldap->assertNotContacted();

        $this->passwordSignIn('carl@example.org', self::DIRECTORY_PASSWORD)->assertCreated()->assertJsonPath('data.role', 'mailboxUser');
    }

    public function test_a_malformed_username_never_reaches_the_directory(): void
    {
        $ldap = $this->directory();

        $this->passwordSignIn('carl@example.org)(uid=*', self::DIRECTORY_PASSWORD)->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/sign-in-failed');

        $ldap->assertNotContacted();
    }

    public function test_the_template_update_happens_before_the_second_factor_step(): void
    {
        $this->staffTemplate();
        $this->directory();
        $this->mailcow()->tfaTotp('carl@example.org', 'JBSWY3DPEHPK3PXP');

        $this->passwordSignIn('carl@example.org', self::DIRECTORY_PASSWORD)->assertCreated()->assertJsonPath('data.state', 'pendingSecondFactor');

        $this->assertSame(['Carl Example', 2147483648], [(string) DB::table('mailbox')->where('username', 'carl@example.org')->value('name'), (int) DB::table('mailbox')->where('username', 'carl@example.org')->value('quota')]);
    }
}
