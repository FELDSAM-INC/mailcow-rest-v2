<?php

namespace Tests\Feature\SelfService;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\TestCase;

/**
 * T017 (feature 005 FR-080): getSelfServiceCapabilities, the server settings and limits a client
 * needs before it submits; signed-out clients learn only whether password reset works.
 */
class SelfServiceCapabilitiesTest extends TestCase
{
    use ApiKeys;
    use MailboxTestHelpers;

    private function configureReset(string $from = 'noreply@example.org', string $subject = 'Reset'): void
    {
        $this->redis()->set('PW_RESET_FROM', $from);
        $this->redis()->set('PW_RESET_SUBJ', $subject);
    }

    public function test_signed_out_clients_get_only_the_reset_availability(): void
    {
        $this->configureReset();

        $this->getJson('/api/v2/capabilities/self-service')
            ->assertOk()
            ->assertExactJson(['data' => ['passwordReset' => ['available' => true, 'tokenLifetimeMinutes' => 15]]]);
    }

    public function test_the_reset_is_unavailable_without_a_sender_or_a_subject(): void
    {
        $this->getJson('/api/v2/capabilities/self-service')->assertOk()->assertJsonPath('data.passwordReset.available', false);

        $this->configureReset('noreply@example.org', '');
        $this->getJson('/api/v2/capabilities/self-service')->assertOk()->assertJsonPath('data.passwordReset.available', false);

        $this->configureReset('', 'Reset');
        $this->getJson('/api/v2/capabilities/self-service')->assertOk()->assertJsonPath('data.passwordReset.available', false);

        config(['restv2.vars_overrides.PW_RESET_TOKEN_LIFETIME' => '30']);
        $this->configureReset();
        $this->getJson('/api/v2/capabilities/self-service')->assertOk()->assertJsonPath('data.passwordReset.tokenLifetimeMinutes', 30);
    }

    public function test_a_read_only_key_gets_every_member_but_the_account(): void
    {
        $this->mailcow()->domainAdmin('reseller', ['example.org']);
        $key = $this->apiKey('reseller', 'readOnly');

        $data = $this->getJson('/api/v2/capabilities/self-service', $this->keyHeader($key))->assertOk()->json('data');

        $this->assertSame(['passwordReset', 'groupware', 'temporaryAliases', 'recentLogins', 'bulkLimit', 'pageSize', 'limits', 'pushoverSounds', 'secondFactors'], array_keys($data));
        $this->assertSame(['enabled' => true, 'webmailUrl' => '/SOGo/so/'], $data['groupware']);
        $this->assertSame(['minLifetimeHours' => 1, 'maxLifetimeHours' => 87600, 'defaultLifetimeHours' => 8760], $data['temporaryAliases']);
        $this->assertSame(['minDays' => 1, 'maxDays' => 31, 'defaultDays' => 7, 'countryLookup' => true], $data['recentLogins']);
        $this->assertSame(100, $data['bulkLimit']);
        $this->assertSame(['default' => 25, 'maximum' => 1000], $data['pageSize']);
        $this->assertSame([
            'nameMaxLength' => 255,
            'descriptionMaxLength' => 255,
            'sieveScriptMaxBytes' => 65535,
            'pushTitleMaxLength' => 250,
            'pushTextMaxLength' => 1024,
            'pushSendersMax' => 100,
            'pushSenderPatternMaxLength' => 1024,
            'pushSoundMaxLength' => 20,
        ], $data['limits']);
        $this->assertContains('bike', $data['pushoverSounds']);
        $this->assertSame('pushover', $data['pushoverSounds'][0]);
        $this->assertSame(['totpIssuer' => 'mailcow UI', 'totpEnrolmentLifetimeSeconds' => 600, 'totpEnrolmentMaxAttempts' => 5, 'webauthnOptionsLifetimeSeconds' => 300], $data['secondFactors']);
    }

    public function test_a_session_also_gets_its_account(): void
    {
        $this->mailcow()->admin('admin', 'Passw0rd!', attributes: ['force_tfa' => '1']);
        $this->mailcow()->tfaTotp('admin', 'JBSWY3DPEHPK3PXP');
        $this->signInAs('admin', Role::Admin);

        $this->getJson('/api/v2/capabilities/self-service')
            ->assertOk()
            ->assertJsonPath('data.account', ['role' => 'admin', 'secondFactorRequired' => true, 'passwordChangeAvailable' => true, 'secondFactorManagementAvailable' => true]);
    }

    public function test_an_integer_force_tfa_also_requires_a_second_factor(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        DB::table('mailbox')->where('username', 'anna@example.org')->update(['attributes' => DB::raw("JSON_SET(`attributes`, '$.force_tfa', 1)")]);
        $this->mailcow()->tfaTotp('anna@example.org', 'JBSWY3DPEHPK3PXP');
        $this->signInAs('anna@example.org', Role::MailboxUser);

        $this->getJson('/api/v2/capabilities/self-service')->assertOk()->assertJsonPath('data.account.secondFactorRequired', true);
    }

    public function test_an_external_source_mailbox_may_not_manage_its_password_or_second_factors(): void
    {
        $this->mailcow()->ensureDomain('example.org');
        $this->mailcow()->mailbox('ldap@example.org', authsource: 'ldap');
        $this->signInAs('ldap@example.org', Role::MailboxUser);

        $this->getJson('/api/v2/capabilities/self-service')
            ->assertOk()
            ->assertJsonPath('data.account', ['role' => 'mailboxUser', 'secondFactorRequired' => false, 'passwordChangeAvailable' => false, 'secondFactorManagementAvailable' => false]);
    }

    public function test_the_groupware_is_reported_disabled(): void
    {
        config(['restv2.skip_sogo' => true]);
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $this->signInAs('anna@example.org', Role::MailboxUser);

        $this->getJson('/api/v2/capabilities/self-service')->assertOk()->assertJsonPath('data.groupware.enabled', false);
    }

    public function test_a_restricted_session_may_read_it_and_the_read_changes_nothing(): void
    {
        $this->configureReset();
        $this->mailcow()->mailboxWithDomain('bob@example.org', ['force_pw_update' => '1']);
        $this->signInAs('bob@example.org', Role::MailboxUser);
        $before = $this->redis()->dbSize();

        $body = $this->getJson('/api/v2/capabilities/self-service')->assertOk()->getContent();

        $this->assertSame($before, $this->redis()->dbSize());
        $this->assertSame(0, DB::table('restv2_audit_events')->count());
        $this->assertStringNotContainsString('noreply@example.org', (string) $body);
        $this->assertStringNotContainsString('Passw0rd', (string) $body);
    }
}
