<?php

namespace Tests\Feature\OwnAccount;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T073 (feature 005 FR-069, US5 AS-6, AS-7): getOwnMailboxOverview shows what the legacy user page
 * shows, as plain text.
 */
class MailboxOverviewTest extends TestCase
{
    use ApiKeys;
    use SelfServiceTestHelpers;

    public function test_the_overview_matches_the_legacy_user_page(): void
    {
        $this->mailcow()->ensureDomain('example.org');
        $this->mailcow()->ensureDomain('example.com');
        $this->mailcow()->mailbox('anna@example.org', quota: 1024 * 1048576, attributes: [
            'recovery_email' => 'anna.private@example.net',
            'passwd_update' => '2026-10-01 12:00:00',
            'pop3_access' => '0',
            'sogo_access' => '0',
        ]);
        DB::table('mailbox')->where('username', 'anna@example.org')->update(['name' => 'Anna & Co', 'attributes' => DB::raw("JSON_REMOVE(`attributes`, '$.dav_access')")]);
        $this->mailcow()->usage('anna@example.org', 256 * 1048576, 42);
        $this->mailcow()->aliasDomain('example.net', 'example.org');
        $this->mailcow()->aliasDomain('münchen-alias.de', 'example.org');
        $this->mailcow()->alias('anna@example.org', 'anna@example.org');
        $this->mailcow()->alias('info@example.org', 'anna@example.org');
        $this->mailcow()->alias('a.smith@example.org', 'anna@example.org', publicComment: 'Old &amp; new');
        $this->mailcow()->alias('team@example.org', 'anna@example.org,carl@example.org', publicComment: 'A &amp; B');
        $this->mailcow()->alias('@example.org', 'anna@example.org');
        $this->mailcow()->senderAcl('anna@example.org', 'boss@example.org');
        $this->mailcow()->senderAcl('anna@example.org', '@example.com');
        $this->mailcow()->senderAcl('anna@example.org', 'outside@example.net', 1);
        $this->mailcow()->aliasDomain('example.biz', 'example.com');
        $this->mailcow()->appPasswd('anna@example.org', 'Phone');
        $this->mailcow()->appPasswd('anna@example.org', 'Old', active: 0);
        $this->mailcow()->temporaryAliasRow('abc.def@example.org', 'anna@example.org');
        $this->mailcow()->temporaryAliasRow('old.one@example.org', 'anna@example.org', validity: time() - 60);
        $this->mailcow()->pushoverSettings('anna@example.org', str_repeat('k', 30), str_repeat('t', 30));
        $this->signInAs('anna@example.org', Role::MailboxUser);

        $data = $this->getJson('/api/v2/me/mailbox')->assertOk()->json('data');

        $this->assertSame('anna@example.org', $data['address']);
        $this->assertSame('anna', $data['localPart']);
        $this->assertSame('Anna & Co', $data['displayName']);
        $this->assertSame('mailcow', $data['authSource']);
        $this->assertSame(['quotaBytes' => 1073741824, 'usedBytes' => 268435456, 'messages' => 42, 'percentUsed' => 25], $data['quota']);
        $this->assertSame(['imap' => true, 'pop3' => false, 'smtp' => true, 'sieve' => true, 'activeSync' => true, 'dav' => true], $data['protocolAccess']);
        $this->assertSame(['enabled' => true, 'redirectAfterSignIn' => false], $data['webmail']);
        $this->assertSame('2026-10-01T10:00:00Z', $data['passwordChangedAt']);
        $this->assertSame('anna.private@example.net', $data['recoveryEmail']);
        $this->assertSame([['address' => 'a.smith@example.org', 'publicComment' => 'Old & new'], ['address' => 'info@example.org', 'publicComment' => null]], $data['directAliases']);
        $this->assertSame([['address' => 'team@example.org', 'publicComment' => 'A & B']], $data['sharedAliases']);
        $this->assertSame([
            ['address' => 'anna@example.net', 'aliasDomain' => 'example.net'],
            ['address' => 'anna@münchen-alias.de', 'aliasDomain' => 'münchen-alias.de'],
        ], $data['aliasDomainAddresses']);
        $this->assertSame(['addresses' => ['boss@example.org'], 'domains' => ['@example.biz', '@example.com'], 'anyAddress' => false], $data['sendAs']);
        $this->assertSame(['@example.org'], $data['catchAllAddresses']);
        $this->assertSame(['example.org', 'example.net', 'münchen-alias.de'], $data['temporaryAliasDomains']);
        $this->assertSame(1, $data['activeAppPasswords']);
        $this->assertSame(1, $data['activeTemporaryAliases']);
        $this->assertTrue($data['pushNotificationsActive']);
    }

    public function test_an_unlimited_quota_has_no_percentage_and_any_address_is_reported(): void
    {
        config(['restv2.master' => false]);
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $this->mailcow()->usage('anna@example.org', 5, 1, replica: true);
        $this->mailcow()->senderAcl('anna@example.org', '*');
        $this->signInAs('anna@example.org', Role::MailboxUser);

        $data = $this->getJson('/api/v2/me/mailbox')->assertOk()->json('data');

        $this->assertNull($data['quota']['percentUsed']);
        $this->assertSame(5, $data['quota']['usedBytes']);
        $this->assertTrue($data['sendAs']['anyAddress']);
        $this->assertFalse($data['pushNotificationsActive']);
    }

    public function test_an_external_source_shows_no_recovery_address(): void
    {
        $this->mailcow()->ensureDomain('example.org');
        $this->mailcow()->mailbox('ldap@example.org', authsource: 'ldap', attributes: ['recovery_email' => 'x@example.net']);
        $this->signInAs('ldap@example.org', Role::MailboxUser);

        $this->getJson('/api/v2/me/mailbox')->assertOk()->assertJsonPath('data.recoveryEmail', null)->assertJsonPath('data.authSource', 'ldap');
    }

    public function test_other_roles_and_keys_are_refused(): void
    {
        $this->asAdmin();
        $this->getJson('/api/v2/me/mailbox')->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');
        $this->asDomainAdmin(['example.org']);
        $this->getJson('/api/v2/me/mailbox')->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->forgetCookies();
        $this->getJson('/api/v2/me/mailbox', $this->keyHeader($this->apiKey('admin')))->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/session-required');
    }
}
