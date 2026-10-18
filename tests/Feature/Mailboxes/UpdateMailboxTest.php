<?php

namespace Tests\Feature\Mailboxes;

use App\Auth\Role;
use App\Support\Hashing\LegacyPasswordVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\TestCase;

/**
 * Feature 003 T053 (FR-029–FR-034, FR-038, FR-057, US3): updateMailbox changes the values sent with
 * legacy's side effects, guarded by the version tag, and audits each change without the password.
 */
class UpdateMailboxTest extends TestCase
{
    use MailboxTestHelpers;

    private const PASSWORD = 'N3w-Passw0rd!';

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
        $this->mailcow()->legacyMailbox('anna@example.org', quota: self::GIB, attributes: ['attribute_hash' => 'abc123']);
        $this->mailcow()->appPassword('anna@example.org');
    }

    public function test_a_new_password_is_stored_in_the_scheme_and_never_shown(): void
    {
        $this->asAdmin();
        $before = $this->mailboxAttributes('anna@example.org')['passwd_update'];
        DB::update("UPDATE `mailbox` SET `attributes` = JSON_SET(`attributes`, '$.passwd_update', '2020-01-01 00:00:00') WHERE `username` = ?", ['anna@example.org']);

        $response = $this->patchMailbox('anna@example.org', ['password' => self::PASSWORD])->assertOk();

        $this->assertTrue((new LegacyPasswordVerifier)->verify((string) $this->mailboxRow('anna@example.org')?->password, self::PASSWORD));
        $this->assertNotSame('2020-01-01 00:00:00', $this->mailboxAttributes('anna@example.org')['passwd_update']);
        $this->assertNotNull($before);
        $this->assertSame('0', $this->mailboxAttributes('anna@example.org')['force_pw_update']);
        $this->assertSame(1, DB::table('app_passwd')->where('mailbox', 'anna@example.org')->count());
        $this->assertStringNotContainsString(self::PASSWORD, (string) $response->getContent());

        $record = $this->auditOf('mailboxUpdate')[0];
        $this->assertSame(['password' => ['passwordSet' => true, 'preHashed' => false]], $record->details['changes']);
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($record->details) ?: '');

        $log = storage_path('logs/testing.log');
        $this->assertStringNotContainsString(self::PASSWORD, File::exists($log) ? File::get($log) : '');
    }

    public function test_the_status_follows_into_the_own_alias_and_the_groupware_directory(): void
    {
        $this->asAdmin();
        $this->patchMailbox('anna@example.org', ['displayName' => 'Anna'])->assertOk();
        $this->assertTrue(DB::table('_sogo_static_view')->where('c_uid', 'anna@example.org')->exists());

        $this->patchMailbox('anna@example.org', ['status' => 'loginDisabled'])->assertOk()->assertJsonPath('data.status', 'loginDisabled');
        $this->assertSame([2, 2], [(int) $this->mailboxRow('anna@example.org')?->active, (int) DB::table('alias')->where('address', 'anna@example.org')->value('active')]);
        $this->assertFalse(DB::table('_sogo_static_view')->where('c_uid', 'anna@example.org')->exists());

        $this->patchMailbox('anna@example.org', ['status' => 'inactive'])->assertOk();
        $this->assertSame(0, (int) DB::table('alias')->where('address', 'anna@example.org')->value('active'));

        $this->patchMailbox('anna@example.org', ['status' => 'active'])->assertOk();
        $this->assertTrue(DB::table('_sogo_static_view')->where('c_uid', 'anna@example.org')->exists());
    }

    public function test_a_stale_version_is_refused_and_a_current_one_accepted(): void
    {
        $this->asAdmin();
        $etag = (string) $this->getJson('/api/v2/mailboxes/anna@example.org')->headers->get('ETag');
        DB::table('mailbox')->where('username', 'anna@example.org')->update(['name' => 'Changed in the legacy UI']);

        $this->patchMailbox('anna@example.org', ['displayName' => 'Mine'], ['If-Match' => $etag])->assertStatus(412);
        $this->assertSame('Changed in the legacy UI', $this->mailboxRow('anna@example.org')?->name);

        $current = (string) $this->getJson('/api/v2/mailboxes/anna@example.org')->headers->get('ETag');
        $response = $this->patchMailbox('anna@example.org', ['displayName' => 'Mine'], ['If-Match' => $current])->assertOk();
        $this->assertNotSame($current, $response->headers->get('ETag'));
        $this->patchMailbox('anna@example.org', ['displayName' => 'Star'], ['If-Match' => '*'])->assertOk();
        $this->patchMailbox('anna@example.org', ['displayName' => 'Free'])->assertOk()->assertJsonPath('data.displayName', 'Free');
    }

    public function test_the_quota_may_fall_below_the_usage_but_not_exceed_the_largest_allowed(): void
    {
        $this->mailcow()->legacyMailbox('big@example.org', quota: 8 * self::GIB);
        $this->mailcow()->usage('anna@example.org', 4 * self::GIB, 10);
        $this->asAdmin();

        $this->patchMailbox('anna@example.org', ['quota' => 2 * self::GIB])->assertOk()->assertJsonPath('data.quota', 2 * self::GIB);
        $this->patchMailbox('anna@example.org', ['quota' => 3 * self::GIB])->assertStatus(422)
            ->assertJsonPath('errors.quota.0.code', 'outOfRange')
            ->assertJsonPath('errors.quota.0.params', ['min' => 0, 'max' => 2 * self::GIB, 'limit' => 'largestAllowedQuota']);
    }

    public function test_forced_password_change_restricts_the_next_sign_in(): void
    {
        $this->asAdmin();

        $this->patchMailbox('anna@example.org', ['forcePasswordChange' => true, 'forceSecondFactorSetup' => true])->assertOk();
        $this->assertSame('1', $this->mailboxAttributes('anna@example.org')['force_pw_update']);
        $this->assertSame('1', $this->mailboxAttributes('anna@example.org')['force_tfa']);

        $this->forgetCookies();
        $this->signInAs('anna@example.org', Role::MailboxUser);
        $this->assertContains('passwordChange', (array) $this->getJson('/api/v2/me')->assertOk()->json('data.pendingActions'));
    }

    public function test_switching_the_authentication_source(): void
    {
        $this->mailcow()->identityProvider('ldap');
        $this->mailcow()->legacyMailbox('ext@example.org', authsource: 'ldap');
        $this->asAdmin();

        $this->patchMailbox('ext@example.org', ['authSource' => 'mailcow'])->assertStatus(422)->assertJsonPath('errors.password.0.code', 'required');
        $this->patchMailbox('ext@example.org', ['authSource' => 'mailcow', 'password' => self::PASSWORD])->assertOk()->assertJsonPath('data.authSource', 'mailcow');
        $this->assertTrue((new LegacyPasswordVerifier)->verify((string) $this->mailboxRow('ext@example.org')?->password, self::PASSWORD));

        DB::update("UPDATE `mailbox` SET `attributes` = JSON_SET(`attributes`, '$.force_pw_update', '1') WHERE `username` = ?", ['anna@example.org']);
        $hash = $this->mailboxRow('anna@example.org')?->password;
        $this->patchMailbox('anna@example.org', ['authSource' => 'ldap'])->assertOk()->assertJsonPath('data.forcePasswordChange', false);
        $this->assertSame('0', $this->mailboxAttributes('anna@example.org')['force_pw_update']);
        $this->assertSame($hash, $this->mailboxRow('anna@example.org')?->password, 'The stored hash is kept.');
        $this->patchMailbox('anna@example.org', ['password' => self::PASSWORD])->assertStatus(422)
            ->assertJsonPath('errors.password.0.params', ['field' => 'authSource', 'value' => 'ldap']);
        $this->patchMailbox('anna@example.org', ['forcePasswordChange' => true])->assertStatus(422)->assertJsonPath('errors.forcePasswordChange.0.code', 'conflictsWith');
        $this->patchMailbox('anna@example.org', ['authSource' => 'keycloak'])->assertStatus(422)->assertJsonPath('errors.authSource.0.code', 'notInList');
    }

    public function test_generic_oidc_also_turns_off_the_forced_second_factor(): void
    {
        $this->mailcow()->identityProvider('generic-oidc');
        DB::update("UPDATE `mailbox` SET `attributes` = JSON_SET(`attributes`, '$.force_tfa', '1') WHERE `username` = ?", ['anna@example.org']);
        $this->asAdmin();

        $this->patchMailbox('anna@example.org', ['authSource' => 'genericOidc'])->assertOk()->assertJsonPath('data.forceSecondFactorSetup', false);
    }

    public function test_the_recovery_email(): void
    {
        $this->mailcow()->identityProvider('ldap');
        $this->mailcow()->legacyMailbox('ext@example.org', authsource: 'ldap');
        $this->asAdmin();

        $this->patchMailbox('anna@example.org', ['recoveryEmail' => 'anna@private.example'])->assertOk()->assertJsonPath('data.recoveryEmail', 'anna@private.example');
        $this->assertSame('anna@private.example', $this->mailboxAttributes('anna@example.org')['recovery_email']);
        $this->patchMailbox('anna@example.org', ['recoveryEmail' => null])->assertOk()->assertJsonPath('data.recoveryEmail', null);
        $this->assertSame('', $this->mailboxAttributes('anna@example.org')['recovery_email']);
        $this->patchMailbox('anna@example.org', ['recoveryEmail' => 'x@y.example'])->assertOk();
        $this->patchMailbox('anna@example.org', ['recoveryEmail' => ''])->assertOk()->assertJsonPath('data.recoveryEmail', null);
        $this->patchMailbox('anna@example.org', ['recoveryEmail' => 'not an address'])->assertStatus(422)
            ->assertJsonPath('errors.recoveryEmail.0.params', ['format' => 'email']);
        $this->patchMailbox('ext@example.org', ['recoveryEmail' => 'x@y.example'])->assertStatus(422)
            ->assertJsonPath('errors.recoveryEmail.0.code', 'conflictsWith');
    }

    public function test_every_change_clears_the_template_marker_and_flushes_the_cache_once(): void
    {
        $this->asAdmin();

        $this->patchMailbox('anna@example.org', ['displayName' => 'Anna', 'protocolAccess' => ['imap' => false], 'webmailRedirect' => false])->assertOk()
            ->assertJsonPath('data.protocolAccess.imap', false)
            ->assertJsonPath('data.protocolAccess.pop3', true);

        $attributes = $this->mailboxAttributes('anna@example.org');
        $this->assertSame('', $attributes['attribute_hash']);
        $this->assertSame('0', $attributes['imap_access']);
        $this->assertSame('0', $attributes['sogo_access']);
        $this->assertSame(1, $this->memcached()->flushCount());
    }

    public function test_invalid_read_only_and_unknown_members_are_refused(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        foreach (['address' => 'x@example.org', 'quotaUsed' => 1, 'rateLimit' => null, 'taggedMailHandling' => 'subject', 'relayhostId' => 2, 'bogus' => true] as $member => $value) {
            $this->patchMailbox('anna@example.org', [$member => $value])->assertStatus(422)->assertJsonPath("errors.{$member}.0.code", 'immutable');
        }

        $this->patchMerge('/api/v2/mailboxes/anna@example.org', [], $this->csrf())->assertStatus(422)->assertJsonPath('errors.body.0.code', 'required');
    }

    public function test_a_patch_equal_to_the_stored_values_writes_nothing(): void
    {
        $this->asAdmin();

        $this->patchMailbox('anna@example.org', ['displayName' => 'anna', 'quota' => self::GIB, 'status' => 'active', 'protocolAccess' => ['pop3' => true]])->assertOk();

        $this->assertSame([], $this->auditOf('mailboxUpdate'));
        $this->assertSame('abc123', $this->mailboxAttributes('anna@example.org')['attribute_hash']);
        $this->assertSame(0, $this->memcached()->flushCount());
    }

    public function test_changes_are_audited_and_found_by_target(): void
    {
        $this->mailcow()->legacyMailbox('bob@example.org');
        $this->asAdmin();

        $this->patchMailbox('anna@example.org', ['displayName' => 'Anna', 'quota' => 2 * self::GIB, 'protocolAccess' => ['pop3' => false]])->assertOk();
        $this->patchMailbox('bob@example.org', ['displayName' => 'Bob'])->assertOk();

        $record = $this->auditOf('mailboxUpdate')[0];
        $this->assertSame([
            'displayName' => ['old' => 'anna', 'new' => 'Anna'],
            'quota' => ['old' => self::GIB, 'new' => 2 * self::GIB],
            'protocolAccess.pop3' => ['old' => true, 'new' => false],
        ], $record->details['changes']);
        $this->assertSame(['mailbox', 'anna@example.org', 'Anna'], [$record->target_type, $record->target_id, $record->target_name]);

        $events = (array) $this->getJson('/api/v2/audit-events?targetType=mailbox&targetId=anna@example.org')->assertOk()->json('data');
        $this->assertSame(['mailboxUpdate'], array_column($events, 'eventType'));
    }
}
