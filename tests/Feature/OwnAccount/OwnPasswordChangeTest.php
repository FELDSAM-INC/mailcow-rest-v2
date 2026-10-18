<?php

namespace Tests\Feature\OwnAccount;

use App\Auth\AuthMethod;
use App\Auth\Role;
use App\Support\Hashing\LegacyPasswordVerifier;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\Support\Redis\ChannelSpy;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T030 (feature 005 US1, FR-008–FR-015): changeOwnPassword for every role, including the forced
 * password change of a restricted session.
 */
class OwnPasswordChangeTest extends TestCase
{
    use ApiKeys;
    use SelfServiceTestHelpers;

    private const NEW_PASSWORD = 'N3w!Passw0rd';

    private function verifies(string $hash, string $password): bool
    {
        return (new LegacyPasswordVerifier)->verify($hash, $password);
    }

    public function test_a_mailbox_user_changes_the_password_and_ends_the_other_sessions(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@example.org', ['force_pw_update' => '0']);
        $appPassword = $this->mailcow()->appPassword('anna@example.org');
        $appPasswordRow = (array) DB::table('app_passwd')->where('id', $appPassword)->first();
        $first = $this->signInAs('anna@example.org', Role::MailboxUser);
        $second = $this->signInAs('anna@example.org', Role::MailboxUser);
        $current = $this->signInAs('anna@example.org', Role::MailboxUser);
        DB::table('mailbox')->where('username', 'anna@example.org')->update(['attributes' => DB::raw("JSON_SET(`attributes`, '$.passwd_update', '2020-01-01 00:00:00')")]);

        $response = $this->changeOwnPassword(['currentPassword' => MailcowFactory::PASSWORD, 'newPassword' => self::NEW_PASSWORD])
            ->assertOk()
            ->assertExactJson(['data' => ['sessionsEnded' => 2, 'activeAppPasswords' => 1, 'mailServerCacheSeconds' => 300]]);

        $row = $this->mailboxRow('anna@example.org');
        $this->assertNotNull($row);
        $this->assertStringStartsWith('{BLF-CRYPT}', (string) $row->password);
        $this->assertTrue($this->verifies((string) $row->password, self::NEW_PASSWORD));
        $this->assertFalse($this->verifies((string) $row->password, MailcowFactory::PASSWORD));
        $attributes = $this->mailboxAttributes('anna@example.org');
        $this->assertSame('0', $attributes['force_pw_update']);
        $this->assertNotSame('2020-01-01 00:00:00', $attributes['passwd_update']);
        $this->assertSame(1, $this->memcached()->flushCount());
        $this->assertTrue(DB::table('_sogo_static_view')->where('c_uid', 'anna@example.org')->exists());

        // The current session stays signed in with a new identifier; the others are ended.
        $renewed = (string) $this->sessionCookie();
        $this->assertNotSame($current, $renewed);
        $this->assertFalse($this->sessionExists($current));
        $this->assertFalse($this->sessionExists($first));
        $this->assertFalse($this->sessionExists($second));
        $this->getJson('/api/v2/me')->assertOk();
        $this->useSessionCookie($first);
        $this->getJson('/api/v2/me')->assertStatus(401);

        $this->assertEquals($appPasswordRow, (array) DB::table('app_passwd')->where('id', $appPassword)->first());

        $record = $this->onlyAuditRecord('ownPasswordChange');
        $this->assertSame('success', $record->outcome);
        $this->assertSame('accountPassword', $record->target_type);
        $this->assertSame('anna@example.org', $record->target_id);
        $this->assertSame(['password' => 'changed', 'forcedChangeResolved' => false, 'sessionsEnded' => 2], $record->details);
        $this->assertStringNotContainsString(self::NEW_PASSWORD, (string) json_encode($record));
        $this->assertStringNotContainsString(MailcowFactory::PASSWORD, (string) json_encode($record));
        $response->assertCookie($this->sessionCookieName());
    }

    public function test_the_new_password_signs_in_and_uses_the_configured_scheme(): void
    {
        config(['restv2.mailcow_pass_scheme' => 'SSHA512']);
        $this->asSelfServiceUser();

        $this->changeOwnPassword(['currentPassword' => MailcowFactory::PASSWORD, 'newPassword' => self::NEW_PASSWORD])->assertOk();

        $this->assertStringStartsWith('{SSHA512}', (string) $this->mailboxRow('anna@example.org')?->password);
        $this->forgetCookies();
        $this->passwordSignIn('anna@example.org', self::NEW_PASSWORD)->assertCreated();
        $this->forgetCookies();
        $this->passwordSignIn('anna@example.org', MailcowFactory::PASSWORD)->assertStatus(401);
    }

    public function test_the_other_sessions_may_be_kept(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $other = $this->signInAs('anna@example.org', Role::MailboxUser);
        $this->signInAs('anna@example.org', Role::MailboxUser);

        $this->changeOwnPassword(['currentPassword' => MailcowFactory::PASSWORD, 'newPassword' => self::NEW_PASSWORD, 'endOtherSessions' => false])
            ->assertOk()
            ->assertJsonPath('data.sessionsEnded', 0);

        $this->assertTrue($this->sessionExists($other));
    }

    public function test_a_wrong_current_password_is_refused_audited_and_throttled_without_fail2ban(): void
    {
        $this->asSelfServiceUser();
        $before = (string) $this->mailboxRow('anna@example.org')?->password;
        $channel = ChannelSpy::fail2ban();

        $this->changeOwnPassword(['currentPassword' => 'wrong', 'newPassword' => self::NEW_PASSWORD])
            ->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/current-password-incorrect');
        $this->changeOwnPassword(['currentPassword' => 'wrong', 'newPassword' => self::NEW_PASSWORD])->assertStatus(403);

        $this->assertSame($before, (string) $this->mailboxRow('anna@example.org')?->password);
        $this->assertSame([0.0, 0.5], $this->sleeper()->slept);
        $records = $this->auditRecords('ownPasswordChange');
        $this->assertCount(2, $records);
        $this->assertSame('currentPasswordIncorrect', $records[0]->failure_reason);
        $this->assertSame('anna@example.org', $records[0]->target_id);
        $channel->assertNothingPublished();
        $channel->stop();

        for ($i = 0; $i < 8; $i++) {
            $this->changeOwnPassword(['currentPassword' => 'wrong', 'newPassword' => self::NEW_PASSWORD])->assertStatus(403);
        }

        $this->changeOwnPassword(['currentPassword' => MailcowFactory::PASSWORD, 'newPassword' => self::NEW_PASSWORD])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('type', '/api/v2/problems/too-many-requests');
    }

    public function test_a_correct_current_password_resets_the_failure_count(): void
    {
        $this->asSelfServiceUser();

        $this->changeOwnPassword(['currentPassword' => 'wrong', 'newPassword' => self::NEW_PASSWORD])->assertStatus(403);
        $this->changeOwnPassword(['currentPassword' => MailcowFactory::PASSWORD, 'newPassword' => self::NEW_PASSWORD])->assertOk();
        $this->changeOwnPassword(['currentPassword' => 'wrong', 'newPassword' => 'An0ther!Pass'])->assertStatus(403);

        $this->assertSame([0.0, 0.0], $this->sleeper()->slept);
    }

    public function test_policy_failures_name_every_rule(): void
    {
        $this->asSelfServiceUser();
        $this->passwordPolicy(10, numbers: true);

        $this->changeOwnPassword(['currentPassword' => MailcowFactory::PASSWORD, 'newPassword' => 'secret'])
            ->assertStatus(422)
            ->assertJsonPath('errors.newPassword.0.code', 'tooShort')
            ->assertJsonPath('errors.newPassword.0.params.min', 10)
            ->assertJsonPath('errors.newPassword.1.code', 'missingCharacterClass')
            ->assertJsonPath('errors.newPassword.1.params.characterClass', 'digit');
    }

    public function test_a_pre_hashed_value_is_refused(): void
    {
        $this->asSelfServiceUser();

        $this->changeOwnPassword(['currentPassword' => MailcowFactory::PASSWORD, 'newPassword' => '{SSHA512}abcdefghijklmnop'])
            ->assertStatus(422)
            ->assertJsonPath('errors.newPassword.0.code', 'preHashedPassword')
            ->assertJsonPath('errors.newPassword.0.params.scheme', 'SSHA512');
    }

    public function test_the_current_password_is_checked_before_the_policy(): void
    {
        $this->asSelfServiceUser();

        $this->changeOwnPassword(['currentPassword' => 'wrong', 'newPassword' => 'x'])
            ->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/current-password-incorrect');
    }

    public function test_an_administrator_must_give_the_current_password(): void
    {
        $this->mailcow()->admin('admin', MailcowFactory::PASSWORD, attributes: ['force_pw_update' => '0', 'force_tfa' => '0']);
        $this->signInAs('admin', Role::Admin);

        $this->changeOwnPassword(['newPassword' => self::NEW_PASSWORD])
            ->assertStatus(422)
            ->assertJsonPath('errors.currentPassword.0.code', 'required');

        $this->changeOwnPassword(['currentPassword' => MailcowFactory::PASSWORD, 'newPassword' => self::NEW_PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.activeAppPasswords', 0);

        $this->assertTrue($this->verifies((string) DB::table('admin')->where('username', 'admin')->value('password'), self::NEW_PASSWORD));
        $this->assertSame('0', $this->adminAttributes('admin')['force_pw_update']);
        $this->assertSame(0, $this->memcached()->flushCount());
    }

    public function test_a_domain_administrator_resolves_a_forced_change_without_the_current_password(): void
    {
        $this->mailcow()->domainAdmin('reseller', ['example.org'], attributes: ['force_pw_update' => '1']);
        $this->signInAs('reseller', Role::DomainAdmin);
        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'restricted');

        $this->changeOwnPassword(['newPassword' => self::NEW_PASSWORD])->assertOk();

        $this->assertSame('0', $this->adminAttributes('reseller')['force_pw_update']);
        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'active');
        $this->assertTrue($this->onlyAuditRecord('ownPasswordChange')->details['forcedChangeResolved']);
    }

    public function test_with_both_forced_actions_the_change_lifts_only_the_password_change(): void
    {
        $this->asSelfServiceUser('bob@example.org', ['force_pw_update' => '1', 'force_tfa' => '1']);

        $this->changeOwnPassword(['newPassword' => self::NEW_PASSWORD])->assertOk();

        $this->getJson('/api/v2/session')
            ->assertJsonPath('data.state', 'restricted')
            ->assertJsonPath('data.pendingActions', ['secondFactorSetup']);
    }

    public function test_an_external_source_mailbox_is_refused(): void
    {
        $this->mailcow()->ensureDomain('example.org');
        $this->mailcow()->mailbox('ldap@example.org', authsource: 'ldap');
        $this->signInAs('ldap@example.org', Role::MailboxUser, AuthMethod::Passkey);

        $this->changeOwnPassword(['currentPassword' => 'whatever', 'newPassword' => self::NEW_PASSWORD])
            ->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/password-managed-externally');

        $this->assertSame('externalIdentitySource', $this->onlyAuditRecord('ownPasswordChange')->failure_reason);
    }

    public function test_api_keys_are_refused(): void
    {
        $this->mailcow()->admin('admin', MailcowFactory::PASSWORD);
        $key = $this->apiKey('admin');

        $this->putJson('/api/v2/me/password', ['currentPassword' => MailcowFactory::PASSWORD, 'newPassword' => self::NEW_PASSWORD], $this->keyHeader($key))
            ->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/session-required');
    }

    public function test_without_the_groupware_the_directory_is_not_rebuilt(): void
    {
        config(['restv2.skip_sogo' => true]);
        $this->asSelfServiceUser();

        $this->changeOwnPassword(['currentPassword' => MailcowFactory::PASSWORD, 'newPassword' => self::NEW_PASSWORD])->assertOk();

        $this->assertSame(0, $this->memcached()->flushCount());
        $this->assertFalse(DB::table('_sogo_static_view')->where('c_uid', 'anna@example.org')->exists());
    }

    public function test_legacy_hash_formats_verify_the_current_password(): void
    {
        foreach (['SSHA', 'MD5-CRYPT', 'SHA512-CRYPT'] as $i => $scheme) {
            $username = "user{$i}@example.org";
            $this->mailcow()->ensureDomain('example.org');
            $this->mailcow()->mailbox($username, 'Legacy!Pass1', $scheme);
            $this->signInAs($username, Role::MailboxUser);

            $this->changeOwnPassword(['currentPassword' => 'Legacy!Pass1', 'newPassword' => self::NEW_PASSWORD])->assertOk();
            $this->assertStringStartsWith('{BLF-CRYPT}', (string) $this->mailboxRow($username)?->password);
        }
    }

    public function test_an_account_that_is_a_mailbox_and_an_administrator_changes_only_the_signed_in_account(): void
    {
        $this->mailcow()->mailboxWithDomain('both@example.org');
        $this->mailcow()->admin('both@example.org', MailcowFactory::PASSWORD);
        $adminHash = (string) DB::table('admin')->where('username', 'both@example.org')->value('password');
        $this->signInAs('both@example.org', Role::MailboxUser);

        $this->changeOwnPassword(['currentPassword' => MailcowFactory::PASSWORD, 'newPassword' => self::NEW_PASSWORD])->assertOk();

        $this->assertSame($adminHash, (string) DB::table('admin')->where('username', 'both@example.org')->value('password'));
        $this->assertTrue($this->verifies((string) $this->mailboxRow('both@example.org')?->password, self::NEW_PASSWORD));
    }
}
