<?php

namespace Tests\Feature\Auth;

use Database\Factories\MailcowRows;
use Illuminate\Support\Facades\DB;
use Tests\Support\IdentityProvider\FakeOidcProvider;
use Tests\Support\IdentityProvider\IdentityProviderFixtures;
use Tests\Support\IdentityProvider\ProviderPasswordTestHelpers;
use Tests\Support\Redis\ChannelSpy;
use Tests\TestCase;

/**
 * T069 (FR-032, FR-034 to FR-039, US3 AS-3): a directory or Keycloak user without a mailbox gets one
 * on the first password sign-in.
 */
class ProviderPasswordProvisioningTest extends TestCase
{
    use ProviderPasswordTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staffTemplate();
        $this->mailcow()->ensureDomain('example.org');
    }

    public function test_a_directory_user_without_a_mailbox_gets_one_from_the_mapped_template(): void
    {
        $this->directory();

        $this->passwordSignIn('carl@example.org', self::DIRECTORY_PASSWORD)->assertCreated()
            ->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.authMethod', 'directoryPassword');

        $mailbox = DB::table('mailbox')->where('username', 'carl@example.org')->first();
        $this->assertNotNull($mailbox);
        $this->assertSame(['ldap', 'Carl Example', 2147483648, ''], [$mailbox->authsource, $mailbox->name, (int) $mailbox->quota, $mailbox->password]);
        $this->assertTrue($this->signInAudit()[0]->details['mailboxCreated']);
    }

    public function test_creation_off_or_no_mapping_fail_without_fail2ban(): void
    {
        $this->directory(['login_provisioning' => '']);
        $spy = ChannelSpy::fail2ban();

        $this->passwordSignIn('carl@example.org', self::DIRECTORY_PASSWORD)->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/sign-in-failed');
        $this->mailcow()->identityProviderRows(['login_provisioning' => '1', 'mappers' => '[]', 'templates' => '[]']);
        $this->passwordSignIn('carl@example.org', self::DIRECTORY_PASSWORD)->assertUnauthorized();

        $spy->assertNothingPublished();
        $this->assertSame([], $this->sleeper()->slept);
        $this->assertEqualsCanonicalizing(['autoCreationOff', 'noMatchingMapping'], array_column($this->signInAudit(), 'failure_reason'));
        unset($spy);
    }

    public function test_a_refused_creation_fails_and_leaves_no_mailbox(): void
    {
        $this->directory();
        DB::table('domain')->where('domain', 'example.org')->update(['mailboxes' => 0]);

        $this->passwordSignIn('carl@example.org', self::DIRECTORY_PASSWORD)->assertUnauthorized();

        $this->assertSame(['mailboxCreationFailed', 'mailboxLimitReached'], [$this->signInAudit()[0]->failure_reason, $this->signInAudit()[0]->details['cause']]);
        $this->assertFalse(DB::table('mailbox')->where('username', 'carl@example.org')->exists());
    }

    public function test_a_template_with_forced_second_factor_setup_restricts_the_new_session(): void
    {
        DB::table('templates')->where('template', 'Staff')->update(['attributes' => json_encode(array_merge(self::STAFF, ['force_tfa' => 1]))]);
        $this->directory();

        $this->passwordSignIn('carl@example.org', self::DIRECTORY_PASSWORD)->assertCreated()->assertJsonPath('data.state', 'restricted');
    }

    public function test_a_keycloak_user_is_created_with_the_first_and_last_name(): void
    {
        $this->mailcow()->keycloakConfig(['mailpassword_flow' => '1']);
        FakeOidcProvider::install()
            ->tokenIssued('client_credentials', 'service-token', null)
            ->users([IdentityProviderFixtures::keycloakUser('anna@example.org', MailcowRows::hash('BLF-CRYPT', 'Kc-Passw0rd!'), 'staff', 'Anna', 'Example')]);

        $this->passwordSignIn('anna@example.org', 'Kc-Passw0rd!')->assertCreated()->assertJsonPath('data.authMethod', 'providerPassword');

        $this->assertSame(['keycloak', 'Anna Example'], [(string) DB::table('mailbox')->where('username', 'anna@example.org')->value('authsource'), (string) DB::table('mailbox')->where('username', 'anna@example.org')->value('name')]);
    }

    public function test_the_role_decides_whether_the_provider_is_asked(): void
    {
        $ldap = $this->directory();

        $this->passwordSignIn('carl@example.org', self::DIRECTORY_PASSWORD, 'admin')->assertUnauthorized();
        $ldap->assertNotContacted();

        $this->passwordSignIn('carl@example.org', self::DIRECTORY_PASSWORD, 'mailboxUser')->assertCreated();
    }
}
