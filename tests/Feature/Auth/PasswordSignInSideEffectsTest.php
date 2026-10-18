<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\DB;
use Tests\Support\Auth\AuthRequests;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\Support\Redis\ChannelSpy;
use Tests\Support\WebAuthn\VirtualAuthenticator;
use Tests\TestCase;

/**
 * T055 (FR-004, FR-006, FR-014, FR-026, FR-027, FR-045, FR-046, FR-054): the side effects of
 * createSession, asserted on the real rows, Redis channel and audit records.
 */
class PasswordSignInSideEffectsTest extends TestCase
{
    use AuthRequests;

    private const PASSWORD = MailcowFactory::PASSWORD;

    private const IP = '192.0.2.10';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withServerVariables(['REMOTE_ADDR' => self::IP]);
    }

    public function test_second_factors_disabled_for_the_next_login_are_reactivated(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $this->mailcow()->tfaTotp('alice@example.org', 'JBSWY3DPEHPK3PXP', active: 0);
        $this->mailcow()->tfaU2f('alice@example.org', active: 0);
        $this->mailcow()->tfaHotp('alice@example.org', active: 0);
        $this->mailcow()->mailboxWithDomain('bob@example.org');
        $this->mailcow()->tfaTotp('bob@example.org', 'JBSWY3DPEHPK3PXP', active: 0);

        $this->passwordSignIn('alice@example.org')->assertCreated()->assertJsonPath('data.state', 'active');

        $this->assertSame([1, 1, 1], DB::table('tfa')->where('username', 'alice@example.org')->orderBy('id')->pluck('active')->map(fn ($v) => (int) $v)->all());
        // Other accounts are untouched.
        $this->assertSame(0, (int) DB::table('tfa')->where('username', 'bob@example.org')->value('active'));
    }

    public function test_an_account_with_only_unusable_active_factors_is_not_reactivated(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $this->mailcow()->tfaU2f('alice@example.org', active: 1);
        $inactive = $this->mailcow()->tfaTotp('alice@example.org', 'JBSWY3DPEHPK3PXP', active: 0);

        $this->passwordSignIn('alice@example.org')->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/second-factor-unavailable');

        $this->assertSame(0, (int) DB::table('tfa')->where('id', $inactive)->value('active'));
        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'anonymous');
        $audit = $this->onlyAuditRecord('signIn');
        $this->assertSame('failure', $audit->outcome);
        $this->assertSame('secondFactorUnavailable', $audit->failure_reason);
    }

    public function test_a_mailbox_sign_in_records_a_recent_login(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');

        $this->passwordSignIn('alice@example.org')->assertCreated();

        $rows = DB::table('sasl_log')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('alice@example.org', $rows[0]->username);
        $this->assertSame(self::IP, $rows[0]->real_rip);
        $this->assertSame('MAILCOWUI', $rows[0]->service);
        $this->assertSame(0, (int) $rows[0]->app_password);
    }

    public function test_the_recent_login_is_recorded_when_a_second_factor_is_pending(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $this->mailcow()->tfaTotp('alice@example.org', 'JBSWY3DPEHPK3PXP');

        $this->passwordSignIn('alice@example.org')->assertCreated()->assertJsonPath('data.state', 'pendingSecondFactor');

        $this->assertSame(1, DB::table('sasl_log')->where('username', 'alice@example.org')->count());
    }

    public function test_the_recent_login_service_label_is_configurable(): void
    {
        config(['restv2.sasl_log_service' => 'WEBUI']);
        $this->mailcow()->mailboxWithDomain('alice@example.org');

        $this->passwordSignIn('alice@example.org')->assertCreated();

        $this->assertSame('WEBUI', DB::table('sasl_log')->value('service'));
    }

    public function test_admins_and_domain_admins_record_no_recent_login(): void
    {
        $this->mailcow()->ensureDomain('example.org');
        $this->mailcow()->admin('admin', self::PASSWORD);
        $this->mailcow()->domainAdmin('reseller', ['example.org']);

        $this->passwordSignIn('admin')->assertCreated();
        $this->passwordSignIn('reseller')->assertCreated();

        $this->assertSame(0, DB::table('sasl_log')->count());
    }

    public function test_signing_in_never_changes_a_stored_hash(): void
    {
        $this->mailcow()->admin('admin', self::PASSWORD, 'SSHA256');
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $adminHash = DB::table('admin')->where('username', 'admin')->value('password');
        $mailboxHash = DB::table('mailbox')->where('username', 'alice@example.org')->value('password');

        $this->passwordSignIn('admin')->assertCreated();
        $this->passwordSignIn('alice@example.org')->assertCreated();
        $this->passwordSignIn('alice@example.org', 'wrong')->assertUnauthorized();

        $this->assertSame($adminHash, DB::table('admin')->where('username', 'admin')->value('password'));
        $this->assertSame($mailboxHash, DB::table('mailbox')->where('username', 'alice@example.org')->value('password'));
    }

    public function test_a_wrong_password_is_published_to_fail2ban_with_the_normalised_username(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $spy = ChannelSpy::fail2ban();

        $this->passwordSignIn(' Alice@Example.org ', 'wrong')->assertUnauthorized();

        $spy->assertPublished('mailcow UI: Invalid password for alice@example.org by '.self::IP);
        $this->assertCount(1, $spy->messages());
    }

    public function test_an_unknown_account_and_a_malformed_username_are_published(): void
    {
        $spy = ChannelSpy::fail2ban();

        $this->passwordSignIn('nobody', 'wrong')->assertUnauthorized();
        $this->passwordSignIn('a b!', 'wrong')->assertUnauthorized();

        $spy->assertPublished('mailcow UI: Invalid password for nobody by '.self::IP);
        $spy->assertPublished('mailcow UI: Invalid password for a b! by '.self::IP);
    }

    public function test_an_external_identity_source_publishes_nothing(): void
    {
        $this->mailcow()->ensureDomain('example.org');
        $this->mailcow()->mailbox('ldap@example.org', self::PASSWORD, authsource: 'ldap');
        $spy = ChannelSpy::fail2ban();

        $this->passwordSignIn('ldap@example.org')->assertUnauthorized();

        $spy->assertNothingPublished();
        $this->assertSame('externalIdentitySource', $this->onlyAuditRecord('signIn')->failure_reason);
    }

    public function test_a_successful_sign_in_publishes_nothing(): void
    {
        $this->mailcow()->admin('admin', self::PASSWORD);
        $spy = ChannelSpy::fail2ban();

        $this->passwordSignIn('admin')->assertCreated();

        $spy->assertNothingPublished();
    }

    public function test_a_successful_sign_in_is_audited(): void
    {
        $this->mailcow()->admin('admin', self::PASSWORD);

        $this->passwordSignIn('admin', self::PASSWORD, 'admin', ['User-Agent' => 'Test browser'])->assertCreated();

        $audit = $this->onlyAuditRecord('signIn');
        $this->assertSame('success', $audit->outcome);
        $this->assertSame('admin', $audit->actor_username);
        $this->assertSame('admin', $audit->actor_role);
        $this->assertSame('password', $audit->auth_method);
        $this->assertSame('session', $audit->target_type);
        $this->assertNull($audit->target_id);
        $this->assertSame(self::IP, $audit->client_address);
        $this->assertSame('Test browser', $audit->user_agent);
        $this->assertNull($audit->failure_reason);
        $this->assertSame('admin', $audit->details['requestedRole']);
        $this->assertFalse($audit->details['restricted']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $audit->request_id);
    }

    public function test_failures_are_audited_with_their_reason(): void
    {
        $factory = $this->mailcow();
        $factory->ensureDomain('example.org');
        $factory->admin('inactive-admin', self::PASSWORD, active: 0);
        $factory->mailbox('alice@example.org', self::PASSWORD);
        $factory->mailbox('room@example.org', self::PASSWORD, kind: 'location');

        $this->passwordSignIn('alice@example.org', 'wrong', 'mailboxUser')->assertUnauthorized();
        $this->passwordSignIn('nobody@example.org', 'wrong')->assertUnauthorized();
        $this->passwordSignIn('inactive-admin')->assertUnauthorized();
        $this->passwordSignIn('room@example.org')->assertUnauthorized();

        $records = $this->auditRecords('signIn');
        $this->assertCount(4, $records);
        $this->assertSame(['failure'], array_values(array_unique(array_column($records, 'outcome'))));
        $this->assertSame(
            ['invalidCredentials', 'invalidCredentials', 'accountInactive', 'accountInactive'],
            array_column($records, 'failure_reason'),
        );
        $this->assertSame(['alice@example.org', 'nobody@example.org', 'inactive-admin', 'room@example.org'], array_column($records, 'actor_username'));
        $this->assertSame('mailboxUser', $records[0]->details['requestedRole']);
        $this->assertNull($records[1]->details['requestedRole']);
        $this->assertSame('password', $records[0]->details['step']);
        $this->assertSame('password', $records[0]->auth_method);
        foreach ($records as $record) {
            $this->assertStringNotContainsString(self::PASSWORD, json_encode($record));
            $this->assertStringNotContainsString('wrong', (string) json_encode($record->details));
        }
    }

    public function test_a_forced_password_change_restricts_the_session(): void
    {
        $this->mailcow()->mailboxWithDomain('bob@example.org', ['force_pw_update' => '1']);

        $this->passwordSignIn('bob@example.org')->assertCreated()
            ->assertJsonPath('data.state', 'restricted')
            ->assertJsonPath('data.pendingActions', ['passwordChange']);

        $this->assertTrue($this->onlyAuditRecord('signIn')->details['restricted']);
    }

    public function test_forced_second_factor_setup_without_any_factor_restricts_the_session(): void
    {
        $this->mailcow()->admin('admin', self::PASSWORD, attributes: ['force_tfa' => '1']);

        $this->passwordSignIn('admin')->assertCreated()
            ->assertJsonPath('data.state', 'restricted')
            ->assertJsonPath('data.pendingActions', ['secondFactorSetup']);
    }

    public function test_forced_second_factor_setup_with_an_inactive_factor_is_not_restricted(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org', ['force_tfa' => '1']);
        $this->mailcow()->tfaTotp('alice@example.org', 'JBSWY3DPEHPK3PXP', active: 0);

        // The inactive factor counts as existing, and the sign-in re-activates it.
        $this->passwordSignIn('alice@example.org')->assertCreated()
            ->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.pendingActions', []);
    }

    public function test_forced_second_factor_setup_with_an_active_passkey_is_not_restricted(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org', ['force_tfa' => '1']);
        $credential = (new VirtualAuthenticator)->createCredential('mail.example.org', 'alice@example.org');
        $this->mailcow()->fido2Credential('alice@example.org', $credential);

        $this->passwordSignIn('alice@example.org')->assertCreated()->assertJsonPath('data.state', 'active');
    }

    public function test_both_pending_actions_can_apply(): void
    {
        $this->mailcow()->mailboxWithDomain('bob@example.org', ['force_pw_update' => '1', 'force_tfa' => '1']);

        $this->passwordSignIn('bob@example.org')->assertCreated()
            ->assertJsonPath('data.state', 'restricted')
            ->assertJsonPath('data.pendingActions', ['passwordChange', 'secondFactorSetup']);
    }
}
