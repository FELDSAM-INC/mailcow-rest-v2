<?php

namespace Tests\Feature\Mailboxes;

use App\Auth\Role;
use App\Services\Mailboxes\UserPermissions;
use App\Services\MailSettings\MailSettingsService;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\TestCase;

/**
 * Feature 003 T030 (FR-003, FR-008–FR-011, FR-017, FR-035, US1): getMailbox returns every member of
 * the representation as legacy computes it, hides overlapping permissions from domain
 * administrators lacking the flag, and answers "not found" alike for foreign, resource and unknown
 * addresses.
 */
class GetMailboxTest extends TestCase
{
    use ApiKeys;
    use MailboxTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org', attributes: ['backupmx' => 1]);
    }

    private function seedAnna(): void
    {
        $this->mailcow()->legacyMailbox(
            'anna@example.org',
            quota: 200 * self::MIB,
            attributes: [
                'force_pw_update' => '1',
                'sogo_access' => '0',
                'pop3_access' => '0',
                'tls_enforce_in' => '1',
                'relayhost' => '3',
                'quarantine_notification' => 'daily',
                'quarantine_category' => 'add_header',
                'recovery_email' => 'anna@private.example',
                'passwd_update' => '2026-10-18 12:00:00',
            ],
            userAcl: ['syncjobs' => 1, 'pw_reset' => 0],
            tags: ['Sales', 'alpha'],
            customAttributes: ['department' => 'Sales'],
            name: 'Anna',
        );
        $this->mailcow()->usage('anna@example.org', 101 * self::MIB, 7);
        $this->mailcow()->usage('anna@example.org', 5, 1, replica: true);
        $this->mailcow()->legacyMailbox('bob@example.org', quota: 9900 * self::MIB);
        $this->mailcow()->saslLog('anna@example.org', 'imap', '2026-10-10 10:00:00');
        $this->mailcow()->saslLog('anna@example.org', 'imap', '2026-10-11 10:00:00', '192.0.2.11');
        $this->mailcow()->saslLog('anna@example.org', 'smtp', '2026-10-12 10:00:00');
        $this->mailcow()->saslLog('anna@example.org', 'SSO', '2026-10-13 10:00:00');
        $this->mailcow()->saslLog('anna@example.org', 'MAILCOWUI', '2026-10-14 10:00:00');
        $this->mailcow()->temporaryAlias('t1@example.org', 'anna@example.org', time() + 3600);
        $this->mailcow()->temporaryAlias('t2@example.org', 'anna@example.org', time() - 3600);
        $this->mailcow()->temporaryAlias('t3@example.org', 'anna@example.org', time() - 3600, permanent: 1);
        $this->mailcow()->pushover('anna@example.org');
        $this->redis()->hSet('RL_VALUE', 'anna@example.org', '10 / 1h');
        $this->redis()->hSet('RL_VALUE', 'example.org', '100 / 1d');
        $this->redis()->hSet('RCPT_WANTS_SUBJECT_TAG', 'anna@example.org', '1');
        $this->redis()->hSet('RCPT_WANTS_SUBFOLDER_TAG', 'anna@example.org', '1');
        DB::table('mailbox')->where('username', 'anna@example.org')->update(['created' => '2026-01-01 08:00:00', 'modified' => '2026-02-01 08:00:00']);
    }

    public function test_every_member_is_read_as_legacy_computes_it(): void
    {
        $this->seedAnna();
        $this->asAdmin();

        $response = $this->getJson('/api/v2/mailboxes/anna@example.org')->assertOk();
        $data = $response->json('data');

        $this->assertSame($response->headers->get('ETag'), $data['etag']);
        $this->assertSame([
            'address' => 'anna@example.org',
            'localPart' => 'anna',
            'domain' => 'example.org',
            'domainUnicode' => 'example.org',
            'displayName' => 'Anna',
            'status' => 'active',
            'authSource' => 'mailcow',
            'quota' => 200 * self::MIB,
            'quotaUsed' => 101 * self::MIB,
            'messageCount' => 7,
            'percentUsed' => 51,
            'largestAllowedQuota' => 340 * self::MIB,
            'forcePasswordChange' => true,
            'forceSecondFactorSetup' => false,
            'webmailRedirect' => false,
            'protocolAccess' => ['imap' => true, 'pop3' => false, 'smtp' => true, 'sieve' => true, 'activeSync' => true, 'dav' => true],
            'userPermissions' => array_merge(UserPermissions::DEFAULTS, ['syncjobs' => true, 'pwReset' => false]),
            'tags' => ['alpha', 'Sales'],
            'customAttributes' => ['department' => 'Sales'],
            'recoveryEmail' => 'anna@private.example',
            'passwordChangedAt' => '2026-10-18T10:00:00Z',
            'lastLogins' => ['imap' => '2026-10-11T08:00:00Z', 'pop3' => null, 'smtp' => '2026-10-12T08:00:00Z', 'webmailSso' => '2026-10-13T08:00:00Z'],
            'taggedMailHandling' => 'subject',
            'tlsEnforceInbound' => true,
            'tlsEnforceOutbound' => false,
            'mailSettingsVersion' => MailSettingsService::etag(['taggedMailHandling' => 'subject', 'tlsEnforceInbound' => true, 'tlsEnforceOutbound' => false]),
            'quarantineNotification' => 'daily',
            'quarantineCategory' => 'junk',
            'rateLimit' => ['value' => 10, 'frame' => 'hour'],
            'rateLimitSource' => 'mailbox',
            'relayhostId' => 3,
            'activeTemporaryAliases' => 2,
            'pushNotificationsActive' => true,
            'domainRelayed' => true,
            'createdAt' => '2026-01-01T07:00:00Z',
            'modifiedAt' => '2026-02-01T07:00:00Z',
            'etag' => $data['etag'],
        ], $data);
    }

    public function test_defaults_of_older_mailboxes_and_values_from_the_domain(): void
    {
        $this->seedAnna();
        $this->mailcow()->legacyMailbox('old@example.org', attributes: [
            'force_pw_update' => null, 'force_tfa' => null, 'sogo_access' => null, 'imap_access' => null, 'pop3_access' => null,
            'smtp_access' => null, 'sieve_access' => null, 'eas_access' => null, 'dav_access' => null, 'passwd_update' => null,
            'relayhost' => null, 'quarantine_notification' => null, 'quarantine_category' => null, 'tls_enforce_in' => null,
        ]);
        DB::table('mailbox')->where('username', 'old@example.org')->update(['modified' => null]);
        $this->mailcow()->pushover('old@example.org', active: 0);
        $this->redis()->hSet('RCPT_WANTS_SUBFOLDER_TAG', 'old@example.org', '1');
        $this->asAdmin();

        $data = $this->getJson('/api/v2/mailboxes/old@example.org')->assertOk()->json('data');

        $this->assertSame(['imap' => true, 'pop3' => true, 'smtp' => true, 'sieve' => true, 'activeSync' => true, 'dav' => true], $data['protocolAccess']);
        $this->assertFalse($data['forcePasswordChange']);
        $this->assertFalse($data['forceSecondFactorSetup']);
        $this->assertTrue($data['webmailRedirect']);
        $this->assertNull($data['passwordChangedAt']);
        $this->assertNull($data['relayhostId']);
        $this->assertNull($data['percentUsed'], 'Unlimited quota has no percentage.');
        $this->assertNull($data['modifiedAt']);
        $this->assertNull($data['recoveryEmail']);
        $this->assertSame('hourly', $data['quarantineNotification']);
        $this->assertSame('rejected', $data['quarantineCategory']);
        $this->assertSame(['value' => 100, 'frame' => 'day'], $data['rateLimit']);
        $this->assertSame('domain', $data['rateLimitSource']);
        $this->assertSame('subfolder', $data['taggedMailHandling']);
        $this->assertFalse($data['pushNotificationsActive']);
        $this->assertSame(0, $data['activeTemporaryAliases']);
        $this->assertSame([], $data['tags']);
        $this->assertSame([], $data['customAttributes']);
        $this->assertSame(['imap' => null, 'pop3' => null, 'smtp' => null, 'webmailSso' => null], $data['lastLogins']);
    }

    public function test_a_unix_password_time_and_no_rate_limit(): void
    {
        $this->mailcow()->legacyMailbox('unix@example.org', attributes: ['passwd_update' => 1760788800]);
        $this->asAdmin();

        $data = $this->getJson('/api/v2/mailboxes/unix@example.org')->assertOk()->json('data');

        $this->assertSame('2025-10-18T12:00:00Z', $data['passwordChangedAt']);
        $this->assertNull($data['rateLimit']);
        $this->assertSame('none', $data['rateLimitSource']);
        $this->assertSame('none', $data['taggedMailHandling']);
    }

    public function test_a_non_master_node_reads_the_replica_usage(): void
    {
        $this->seedAnna();
        config(['restv2.master' => false]);
        $this->asAdmin();

        $data = $this->getJson('/api/v2/mailboxes/anna@example.org')->assertOk()->json('data');

        $this->assertSame(5, $data['quotaUsed']);
        $this->assertSame(1, $data['messageCount']);
    }

    public function test_the_largest_allowed_quota_never_falls_below_zero(): void
    {
        $this->mailcow()->legacyMailbox('anna@example.org', quota: 100 * self::MIB);
        $this->mailcow()->legacyMailbox('bob@example.org', quota: 20000 * self::MIB);
        $this->asAdmin();

        $this->assertSame(0, $this->getJson('/api/v2/mailboxes/anna@example.org')->assertOk()->json('data.largestAllowedQuota'));
    }

    public function test_domain_administrators_do_not_see_permissions_whose_flag_they_lack(): void
    {
        $this->seedAnna();
        $this->asDomainAdmin(['example.org'], daAcl: ['syncjobs' => 0, 'quarantine' => 0, 'app_passwds' => 0, 'pushover' => 0, 'spam_policy' => 0]);

        $permissions = $this->getJson('/api/v2/mailboxes/anna@example.org')->assertOk()->json('data.userPermissions');

        foreach (UserPermissions::OVERLAPPING as $name) {
            $this->assertArrayNotHasKey($name, $permissions);
        }

        $this->assertCount(10, $permissions);
    }

    public function test_a_legacy_change_is_visible_with_a_new_tag(): void
    {
        $this->seedAnna();
        $this->asAdmin();
        $before = $this->getJson('/api/v2/mailboxes/anna@example.org')->assertOk();

        DB::table('mailbox')->where('username', 'anna@example.org')->update(['quota' => 300 * self::MIB]);

        $after = $this->getJson('/api/v2/mailboxes/anna@example.org')->assertOk();
        $this->assertSame(300 * self::MIB, $after->json('data.quota'));
        $this->assertNotSame($before->headers->get('ETag'), $after->headers->get('ETag'));
    }

    public function test_foreign_resource_and_unknown_addresses_are_not_found_alike(): void
    {
        $this->mailcow()->domain('other.example');
        $this->mailcow()->legacyMailbox('carl@other.example');
        $this->mailcow()->mailbox('room@example.org', kind: 'location');
        $this->asDomainAdmin(['example.org']);

        $bodies = [];

        foreach (['carl@other.example', 'room@example.org', 'nobody@example.org'] as $address) {
            $body = (array) $this->getJson('/api/v2/mailboxes/'.$address)->assertNotFound()->json();
            unset($body['requestId']);
            $bodies[] = $body;
        }

        $this->assertSame($bodies[0], $bodies[1]);
        $this->assertSame($bodies[0], $bodies[2]);
    }

    public function test_the_address_is_normalised(): void
    {
        $this->mailcow()->domain('xn--bcher-kva.example');
        $this->mailcow()->legacyMailbox('anna@xn--bcher-kva.example');
        $this->asAdmin();

        $this->getJson('/api/v2/mailboxes/ANNA@'.rawurlencode('Bücher.example'))->assertOk()
            ->assertJsonPath('data.address', 'anna@xn--bcher-kva.example')
            ->assertJsonPath('data.domainUnicode', 'bücher.example');
    }

    public function test_mailbox_users_are_refused_and_read_only_keys_may_read(): void
    {
        $this->mailcow()->legacyMailbox('anna@example.org');
        $this->signInAs('anna@example.org', Role::MailboxUser);
        $this->getJson('/api/v2/mailboxes/anna@example.org')->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->forgetCookies();
        $this->mailcow()->admin('admin', MailcowFactory::PASSWORD);
        $this->getJson('/api/v2/mailboxes/anna@example.org', $this->keyHeader($this->apiKey('admin', 'readOnly')))->assertOk();
        $this->getJson('/api/v2/mailboxes/anna@example.org')->assertStatus(401);
    }
}
