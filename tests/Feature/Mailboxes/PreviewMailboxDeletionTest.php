<?php

namespace Tests\Feature\Mailboxes;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\TestCase;

/**
 * Feature 003 T062 (FR-065, US4 AS-6): the deletion preview reports, by the deletion's own rules,
 * what would be removed and moved, the blockers and the version tag, and changes nothing.
 */
class PreviewMailboxDeletionTest extends TestCase
{
    use ApiKeys;
    use MailboxTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
        $this->mailcow()->legacyMailbox('anna@example.org', tags: ['Sales', 'VIP']);
        $this->mailcow()->legacyMailbox('bob@example.org');
    }

    public function test_the_counts_addresses_mail_and_tag(): void
    {
        $f = $this->mailcow();
        $f->alias('a1@example.org', 'anna@example.org');
        $f->alias('a3@example.org', 'anna@example.org');
        $f->alias('a2@example.org', 'anna@example.org');
        $f->alias('shared@example.org', 'anna@example.org,bob@example.org');
        $f->appPassword('anna@example.org');
        $f->appPassword('anna@example.org', 'Laptop');
        $f->tfaTotp('anna@example.org', 'JBSWY3DPEHPK3PXP');
        $f->senderAcl('anna@example.org', 'bob@example.org');
        $f->temporaryAlias('tmp@example.org', 'anna@example.org');
        $f->syncJob('anna@example.org');
        $f->sieveFilter('anna@example.org');
        $f->filterconf('anna@example.org', 'blacklist_from', 'spam@example.net');
        $f->quarantineItem('anna@example.org');
        $f->bccMap('anna@example.org', 'copy@example.net', 'example.org');
        $f->oauthTokens('anna@example.org');
        $f->saslLog('anna@example.org', 'imap', '2026-10-01 10:00:00');
        $f->sogoData('anna@example.org');
        $f->pushover('anna@example.org');
        $f->usage('anna@example.org', 123456, 42);
        $this->redis()->hSet('RL_VALUE', 'anna@example.org', '10 / 1h');
        $this->asAdmin();
        $etag = (string) $this->getJson('/api/v2/mailboxes/anna@example.org')->headers->get('ETag');

        $data = $this->getJson('/api/v2/mailboxes/anna@example.org/deletion-preview')->assertOk()->json('data');

        $this->assertSame([
            'address' => 'anna@example.org',
            'canProceed' => true,
            'blockers' => [],
            'exclusiveAliases' => ['count' => 3, 'addresses' => ['a1@example.org', 'a2@example.org', 'a3@example.org']],
            'sharedAliases' => ['count' => 1, 'addresses' => ['shared@example.org']],
            'counts' => [
                'senderPermissionsHeld' => 1, 'senderPermissionsGranted' => 0, 'temporaryAliases' => 1, 'syncJobs' => 1,
                'appPasswords' => 2, 'sieveFilters' => 1, 'spamFilterSettings' => 1, 'quarantineItems' => 1, 'bccMaps' => 1,
                'oauthGrants' => 3, 'secondFactors' => 1, 'passkeys' => 0, 'tags' => 2, 'recentLogins' => 1,
            ],
            'present' => ['pushNotificationSettings' => true, 'webmailData' => true, 'rateLimit' => true, 'taggedMailHandling' => false],
            'mail' => ['storageUsed' => 123456, 'messageCount' => 42],
            'garbageRetentionMinutes' => 7200,
            'etag' => $etag,
        ], $data);
        $this->assertSame([], $this->auditOf('mailboxDeletion'));
        $this->dockerApi()->assertNothingCalled();
        $this->assertNotNull($this->mailboxRow('anna@example.org'));
    }

    public function test_unreachable_services_are_blockers(): void
    {
        $this->dockerApi()->setReachable('dovecot-mailcow', false);
        $this->asAdmin();

        $data = $this->getJson('/api/v2/mailboxes/anna@example.org/deletion-preview')->assertOk()->json('data');
        $this->assertFalse($data['canProceed']);
        $this->assertSame(['mailStore'], array_column($data['blockers'], 'dependency'));

        $this->breakSharedStore();
        $data = $this->getJson('/api/v2/mailboxes/anna@example.org/deletion-preview')->assertOk()->json('data');
        $this->assertSame(['mailStore', 'sharedStore'], array_column($data['blockers'], 'dependency'));
        $this->assertNull($data['present']['rateLimit']);
        $this->assertNull($data['present']['taggedMailHandling']);
    }

    public function test_scope_and_roles(): void
    {
        $this->mailcow()->domain('other.example');
        $this->mailcow()->legacyMailbox('carl@other.example');
        $this->mailcow()->domainAdmin('reseller', ['example.org']);
        $this->mailcow()->admin('admin', MailcowFactory::PASSWORD);

        $this->getJson('/api/v2/mailboxes/anna@example.org/deletion-preview', $this->keyHeader($this->apiKey('admin', 'readOnly')))->assertOk();
        $this->getJson('/api/v2/mailboxes/carl@other.example/deletion-preview', $this->keyHeader($this->apiKey('reseller', 'readOnly', name: 'da')))->assertNotFound();

        $this->signInAs('anna@example.org', Role::MailboxUser);
        $this->getJson('/api/v2/mailboxes/anna@example.org/deletion-preview')->assertStatus(403);
        $this->assertSame(0, DB::table('restv2_audit_events')->count());
    }
}
