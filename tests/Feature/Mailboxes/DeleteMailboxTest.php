<?php

namespace Tests\Feature\Mailboxes;

use App\Services\Groupware\SogoDirectory;
use Illuminate\Support\Facades\DB;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\TestCase;

/**
 * Feature 003 T058 (FR-039–FR-042, US4 AS-1): a deletion first moves the mail to the garbage area,
 * then removes everything legacy removes, plus the tagged-mail handling, the recent logins and the
 * API's own preferences of the user, keeps the other recipients of shared aliases, and is audited.
 */
class DeleteMailboxTest extends TestCase
{
    use MailboxTestHelpers;

    private const ADDRESS = 'anna@example.org';

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
        $this->mailcow()->legacyMailbox(self::ADDRESS, tags: ['Sales']);
        $this->mailcow()->legacyMailbox('bob@example.org', tags: ['Sales']);
    }

    private function seedDependents(): void
    {
        $f = $this->mailcow();
        $f->alias('only@example.org', self::ADDRESS);
        $f->alias('shared@example.org', 'bob@example.org,'.self::ADDRESS);
        $f->alias('bobonly@example.org', 'bob@example.org');
        $f->senderAcl(self::ADDRESS, 'bob@example.org');
        $f->senderAcl('bob@example.org', self::ADDRESS);
        $f->senderAcl('bob@example.org', 'bobonly@example.org');
        $f->temporaryAlias('tmp@example.org', self::ADDRESS);
        $f->syncJob(self::ADDRESS);
        $f->syncJob('bob@example.org');
        $f->filterconf(self::ADDRESS, 'whitelist_from', 'friend@example.net');
        $f->quarantineItem(self::ADDRESS);
        $f->pushover(self::ADDRESS);
        $f->bccMap(self::ADDRESS, 'archive@example.net', 'example.org');
        $f->sieveFilter(self::ADDRESS);
        $f->appPassword(self::ADDRESS);
        $f->appPassword('bob@example.org');
        $f->sogoData(self::ADDRESS);
        $f->sogoData('bob@example.org');
        $f->oauthTokens(self::ADDRESS);
        $f->tfaTotp(self::ADDRESS, 'JBSWY3DPEHPK3PXP');
        $f->fido2(self::ADDRESS, random_bytes(16), '-----BEGIN PUBLIC KEY-----', 'example.org');
        $f->saslLog(self::ADDRESS, 'imap', '2026-10-01 10:00:00');
        $f->saslLog('bob@example.org', 'imap', '2026-10-01 10:00:00');
        DB::table('restv2_preferences')->insert(['account_kind' => 'mailbox', 'username' => self::ADDRESS, 'language' => 'cs-cz', 'created_at' => now(), 'updated_at' => now()]);
        $this->redis()->hSet('RL_VALUE', self::ADDRESS, '10 / 1h');
        $this->redis()->hSet('RCPT_WANTS_SUBJECT_TAG', self::ADDRESS, '1');
        $this->redis()->hSet('RCPT_WANTS_SUBFOLDER_TAG', self::ADDRESS, '1');
        $this->redis()->hSet('RL_VALUE', 'bob@example.org', '5 / 1m');
    }

    /**
     * Rows that name the deleted address, per table.
     *
     * @return array<string, int>
     */
    private function remaining(string $address): array
    {
        $like = '%/'.$address.'/%';
        $counts = [
            'mailbox' => DB::table('mailbox')->where('username', $address)->count(),
            'quota2' => DB::table('quota2')->where('username', $address)->count(),
            'quota2replica' => DB::table('quota2replica')->where('username', $address)->count(),
            'user_acl' => DB::table('user_acl')->where('username', $address)->count(),
            'tags_mailbox' => DB::table('tags_mailbox')->where('username', $address)->count(),
            'alias' => DB::table('alias')->where('address', $address)->orWhere('goto', 'like', '%'.$address.'%')->count(),
            'sender_acl' => DB::table('sender_acl')->where('logged_in_as', $address)->orWhere('send_as', $address)->count(),
            'spamalias' => DB::table('spamalias')->where('goto', $address)->count(),
            'imapsync' => DB::table('imapsync')->where('user2', $address)->count(),
            'filterconf' => DB::table('filterconf')->where('object', $address)->count(),
            'quarantine' => DB::table('quarantine')->where('rcpt', $address)->count(),
            'pushover' => DB::table('pushover')->where('username', $address)->count(),
            'bcc_maps' => DB::table('bcc_maps')->where('local_dest', $address)->count(),
            'sieve_filters' => DB::table('sieve_filters')->where('username', $address)->count(),
            'app_passwd' => DB::table('app_passwd')->where('mailbox', $address)->count(),
            'sogo_user_profile' => DB::table('sogo_user_profile')->where('c_uid', $address)->count(),
            'sogo_cache_folder' => DB::table('sogo_cache_folder')->where('c_uid', $address)->count(),
            'sogo_folder_info' => DB::table('sogo_folder_info')->where('c_path2', $address)->count(),
            'sogo_acl' => DB::table('sogo_acl')->where('c_object', 'like', $like)->orWhere('c_uid', $address)->count(),
            'oauth' => DB::table('oauth_access_tokens')->where('user_id', $address)->count() + DB::table('oauth_refresh_tokens')->where('user_id', $address)->count() + DB::table('oauth_authorization_codes')->where('user_id', $address)->count(),
            'tfa' => DB::table('tfa')->where('username', $address)->count(),
            'fido2' => DB::table('fido2')->where('username', $address)->count(),
            'sasl_log' => DB::table('sasl_log')->where('username', $address)->count(),
            'restv2_preferences' => DB::table('restv2_preferences')->where('username', $address)->count(),
            '_sogo_static_view' => DB::table('_sogo_static_view')->where('c_uid', $address)->count(),
        ];

        return array_filter($counts);
    }

    public function test_everything_that_names_the_mailbox_is_removed_after_the_mail_was_moved(): void
    {
        $this->seedDependents();
        $this->app->make(SogoDirectory::class)->refresh([self::ADDRESS, 'bob@example.org']);
        $storeRowsAtCleanup = null;
        $this->dockerApi()->beforeAnswer(function () use (&$storeRowsAtCleanup): void {
            $storeRowsAtCleanup = DB::table('mailbox')->where('username', self::ADDRESS)->count();
        });
        $this->asAdmin();

        $this->deleteMailbox(self::ADDRESS)->assertNoContent();

        $this->assertSame(1, $storeRowsAtCleanup, 'The mail was moved before any row was removed.');
        $this->dockerApi()->assertExecuted('dovecot-mailcow', ['cmd' => 'maildir', 'task' => 'cleanup', 'maildir' => 'example.org/anna']);
        $this->assertSame([], $this->remaining(self::ADDRESS));
        $this->assertSame('bob@example.org', DB::table('alias')->where('address', 'shared@example.org')->value('goto'));
        $this->assertSame(2, DB::table('sogo_folder_info')->where('c_path2', 'bob@example.org')->count());
        $this->assertSame(2, DB::table('sogo_store')->count(), 'Only the other user\'s stored items remain.');
        $this->assertNotSame([], $this->remaining('bob@example.org'), 'Other mailboxes keep their rows.');
        $this->assertSame(1, DB::table('imapsync')->where('user2', 'bob@example.org')->count());
        $this->assertSame(1, DB::table('sender_acl')->where('send_as', 'bobonly@example.org')->count());
        $this->assertFalse($this->redis()->hExists('RL_VALUE', self::ADDRESS));
        $this->assertFalse($this->redis()->hExists('RCPT_WANTS_SUBJECT_TAG', self::ADDRESS));
        $this->assertFalse($this->redis()->hExists('RCPT_WANTS_SUBFOLDER_TAG', self::ADDRESS));
        $this->assertSame('5 / 1m', $this->redis()->hGet('RL_VALUE', 'bob@example.org'));
        $this->assertTrue(DB::table('_sogo_static_view')->where('c_uid', 'bob@example.org')->exists());
        $this->assertSame(1, $this->memcached()->flushCount());

        $record = $this->auditOf('mailboxDeletion')[0];
        $this->assertSame(['success', 'mailbox', self::ADDRESS], [$record->outcome, $record->target_type, $record->target_id]);
        $this->assertSame('done', $record->details['mailStoreCleanup']);
        $this->assertSame([
            'exclusiveAliases' => 1, 'sharedAliases' => 1, 'senderPermissionsHeld' => 1, 'senderPermissionsGranted' => 1,
            'temporaryAliases' => 1, 'syncJobs' => 1, 'appPasswords' => 1, 'sieveFilters' => 1, 'spamFilterSettings' => 1,
            'quarantineItems' => 1, 'bccMaps' => 1, 'oauthGrants' => 3, 'secondFactors' => 1, 'passkeys' => 1, 'tags' => 1,
            'recentLogins' => 1,
        ], $record->details['removed']);
    }

    public function test_webmail_data_is_removed_also_when_the_groupware_is_disabled(): void
    {
        $this->mailcow()->sogoData(self::ADDRESS);
        DB::table('_sogo_static_view')->insert(['c_uid' => 'unrelated@example.org', 'domain' => 'example.org', 'c_name' => 'unrelated@example.org', 'mail' => 'unrelated@example.org', 'aliases' => '']);
        config(['restv2.skip_sogo' => true]);
        $this->asAdmin();

        $this->deleteMailbox(self::ADDRESS)->assertNoContent();

        $this->assertSame(0, DB::table('sogo_user_profile')->where('c_uid', self::ADDRESS)->count());
        $this->assertSame(0, DB::table('sogo_folder_info')->where('c_path2', self::ADDRESS)->count());
        $this->assertTrue(DB::table('_sogo_static_view')->where('c_uid', 'unrelated@example.org')->exists(), 'The groupware directory is not touched.');
        $this->assertSame(0, $this->memcached()->flushCount());
    }

    public function test_a_domain_administrator_deletes_in_its_own_domain(): void
    {
        $this->asDomainAdmin(['example.org']);

        $this->deleteMailbox(self::ADDRESS)->assertNoContent();
        $this->assertNull($this->mailboxRow(self::ADDRESS));
    }
}
