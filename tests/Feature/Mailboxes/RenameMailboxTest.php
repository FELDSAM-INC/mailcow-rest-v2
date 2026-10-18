<?php

namespace Tests\Feature\Mailboxes;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\TestCase;

/**
 * Feature 003 T079 (FR-043, FR-045–FR-047, US8 AS-1): a rename does everything legacy does, carries
 * over what legacy leaves under the old address, revokes OAuth grants, optionally keeps the old
 * address as an alias, and records every step.
 */
class RenameMailboxTest extends TestCase
{
    use MailboxTestHelpers;

    private const OLD = 'anna@example.org';

    private const NEW = 'anna.smith@example.org';

    /** @var list<array<string, mixed>> */
    private const ACL = [
        ['user' => self::OLD, 'id' => 'bob@example.org', 'mailbox' => 'Projects', 'rights' => ['lookup', 'read']],
        ['user' => 'bob@example.org', 'id' => self::OLD, 'mailbox' => 'Shared', 'rights' => ['lookup', 'read', 'write']],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
        $this->mailcow()->legacyMailbox(self::OLD, tags: ['Sales'], name: 'Anna');
        $this->mailcow()->legacyMailbox('bob@example.org');
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function rename(string $address, string $newLocalPart, bool $keep, array $headers = []): TestResponse
    {
        return $this->postJson('/api/v2/mailboxes/'.$address.'/rename', ['newLocalPart' => $newLocalPart, 'keepOldAddressAsAlias' => $keep], $this->csrf() + $headers);
    }

    private function seedEverything(): void
    {
        $f = $this->mailcow();
        $f->sieveFilter(self::OLD);
        $f->appPassword(self::OLD);
        $f->pushover(self::OLD);
        $f->alias('sales@example.org', self::OLD);
        $f->alias('team@example.org', 'bob@example.org,'.self::OLD);
        $f->syncJob(self::OLD);
        $f->bccMap(self::OLD, 'archive@example.net', 'example.org');
        $f->bccMap('bob@example.org', self::OLD, 'example.org');
        $f->recipientMap(self::OLD, 'bob@example.org');
        $f->recipientMap('old-team@example.org', self::OLD);
        $f->senderAcl(self::OLD, 'sales@example.org');
        $f->senderAcl('bob@example.org', self::OLD);
        $f->tfaTotp(self::OLD, 'JBSWY3DPEHPK3PXP');
        $f->fido2(self::OLD, random_bytes(16), '-----BEGIN PUBLIC KEY-----', 'example.org');
        $f->temporaryAlias('tmp@example.org', self::OLD);
        $f->filterconf(self::OLD, 'whitelist_from', 'friend@example.net');
        $f->quarantineItem(self::OLD);
        $f->saslLog(self::OLD, 'imap', '2026-10-01 10:00:00');
        $f->oauthTokens(self::OLD);
        $f->domainAdmin(self::OLD, ['example.org'], ['syncjobs' => 1]);
        DB::table('restv2_preferences')->insert(['account_kind' => 'mailbox', 'username' => self::OLD, 'theme' => 'dark', 'created_at' => now(), 'updated_at' => now()]);
        $this->redis()->hSet('RL_VALUE', self::OLD, '10 / 1h');
        $this->redis()->hSet('RCPT_WANTS_SUBJECT_TAG', self::OLD, '1');
        $this->dockerApi()->aclList(self::ACL);
    }

    public function test_everything_follows_the_mailbox_to_its_new_address(): void
    {
        $this->seedEverything();
        $this->asAdmin();

        $response = $this->rename(self::OLD, ' Anna.Smith ', true)->assertOk()
            ->assertHeader('Content-Location', '/api/v2/mailboxes/'.self::NEW)
            ->assertJsonPath('data.address', self::NEW)
            ->assertJsonPath('data.previousAddress', self::OLD)
            ->assertJsonPath('data.oldAddressKeptAsAlias', true);
        $this->assertSame($response->headers->get('ETag'), $response->json('data.etag'));

        $this->assertNull($this->mailboxRow(self::OLD));
        $this->assertSame('anna.smith', $this->mailboxRow(self::NEW)?->local_part);

        foreach ([['tags_mailbox', 'username'], ['sieve_filters', 'username'], ['app_passwd', 'mailbox'], ['user_acl', 'username'], ['quota2', 'username'], ['quota2replica', 'username'], ['pushover', 'username'], ['imapsync', 'user2'], ['tfa', 'username'], ['fido2', 'username'], ['spamalias', 'goto'], ['filterconf', 'object'], ['quarantine', 'rcpt'], ['sasl_log', 'username']] as [$table, $column]) {
            $this->assertSame(0, DB::table($table)->where($column, self::OLD)->count(), "{$table}.{$column} still names the old address.");
            $this->assertGreaterThan(0, DB::table($table)->where($column, self::NEW)->count(), "{$table}.{$column} does not name the new address.");
        }

        $this->assertSame(self::NEW, DB::table('alias')->where('address', self::NEW)->value('goto'));
        $this->assertSame(self::NEW, DB::table('alias')->where('address', 'sales@example.org')->value('goto'));
        $this->assertSame('bob@example.org,'.self::NEW, DB::table('alias')->where('address', 'team@example.org')->value('goto'));
        $this->assertSame(1, DB::table('bcc_maps')->where('local_dest', self::NEW)->count());
        $this->assertSame(1, DB::table('bcc_maps')->where('bcc_dest', self::NEW)->count());
        $this->assertSame(1, DB::table('recipient_maps')->where('old_dest', self::NEW)->count());
        $this->assertSame(1, DB::table('recipient_maps')->where('new_dest', self::NEW)->count());
        $this->assertSame(1, DB::table('sender_acl')->where('logged_in_as', self::NEW)->count());
        $this->assertSame(1, DB::table('sender_acl')->where('send_as', self::NEW)->count());
        $this->assertSame(1, DB::table('restv2_preferences')->where('username', self::NEW)->count());
        $this->assertSame(0, DB::table('oauth_access_tokens')->count() + DB::table('oauth_refresh_tokens')->count() + DB::table('oauth_authorization_codes')->count());
        $this->assertSame(1, (int) DB::table('da_acl')->where('username', self::OLD)->value('syncjobs'), 'The domain administrator account keeps its flags.');
        $this->assertSame('10 / 1h', $this->redis()->hGet('RL_VALUE', self::NEW));
        $this->assertFalse($this->redis()->hExists('RL_VALUE', self::OLD));
        $this->assertSame('1', $this->redis()->hGet('RCPT_WANTS_SUBJECT_TAG', self::NEW));
        $this->assertTrue(DB::table('_sogo_static_view')->where('c_uid', self::NEW)->exists());
        $this->assertFalse(DB::table('_sogo_static_view')->where('c_uid', self::OLD)->exists());

        $alias = DB::table('alias')->where('address', self::OLD)->first();
        $this->assertSame([self::NEW, 'Mailbox was renamed from '.self::OLD.' to '.self::NEW, 1, 0, 0, 1], [$alias?->goto, $alias?->private_comment, (int) $alias?->sogo_visible, (int) $alias?->internal, (int) $alias?->sender_allowed, (int) $alias?->active]);

        $this->assertSame([
            ['dovecot-mailcow', ['cmd' => 'doveadm', 'task' => 'get_acl', 'id' => self::OLD]],
            ['dovecot-mailcow', ['cmd' => 'doveadm', 'task' => 'delete_acl', 'user' => self::OLD, 'mailbox' => 'Projects', 'id' => 'bob@example.org']],
            ['dovecot-mailcow', ['cmd' => 'doveadm', 'task' => 'delete_acl', 'user' => 'bob@example.org', 'mailbox' => 'Shared', 'id' => self::OLD]],
            ['dovecot-mailcow', ['cmd' => 'maildir', 'task' => 'move', 'old_maildir' => 'example.org/anna', 'new_maildir' => 'example.org/anna.smith']],
            ['sogo-mailcow', ['cmd' => 'sogo', 'task' => 'rename_user', 'old_username' => self::OLD, 'new_username' => self::NEW]],
            ['dovecot-mailcow', ['cmd' => 'doveadm', 'task' => 'set_acl', 'user' => self::NEW, 'mailbox' => 'Projects', 'id' => 'bob@example.org', 'rights' => ['lookup', 'read']]],
            ['dovecot-mailcow', ['cmd' => 'doveadm', 'task' => 'set_acl', 'user' => 'bob@example.org', 'mailbox' => 'Shared', 'id' => self::NEW, 'rights' => ['lookup', 'read', 'write']]],
        ], $this->dockerApi()->commands());
        $this->assertSame(1, $this->memcached()->flushCount());
        $this->getJson('/api/v2/mailboxes/'.self::OLD)->assertNotFound();

        $record = $this->auditOf('mailboxRename')[0];
        $this->assertSame(['success', self::OLD], [$record->outcome, $record->target_id]);
        $this->assertSame([self::OLD, self::NEW, true], [$record->details['previousAddress'], $record->details['newAddress'], $record->details['keepOldAddressAsAlias']]);
        $this->assertSame(array_fill(0, 6, 'done'), array_column($record->details['steps'], 'outcome'));
        $this->assertSame(['sharedFolderPermissionsRemoval', 'sharedStoreMove', 'database', 'mailStoreMove', 'webmailRename', 'sharedFolderPermissionsRecreation'], array_column($record->details['steps'], 'step'));
    }

    public function test_without_keeping_the_old_address_no_alias_is_created(): void
    {
        $this->asAdmin();

        $this->rename(self::OLD, 'anna.smith', false)->assertOk()->assertJsonPath('data.oldAddressKeptAsAlias', false);

        $this->assertFalse(DB::table('alias')->where('address', self::OLD)->exists());
    }

    public function test_a_disabled_groupware_skips_the_webmail_rename(): void
    {
        config(['restv2.skip_sogo' => true]);
        $this->dockerApi()->setReachable('sogo-mailcow', false);
        $this->asAdmin();

        $this->rename(self::OLD, 'anna.smith', false)->assertOk();

        $this->dockerApi()->assertNotExecuted('sogo-mailcow', ['cmd' => 'sogo', 'task' => 'rename_user', 'old_username' => self::OLD, 'new_username' => self::NEW]);
        $this->assertSame('skipped', $this->auditOf('mailboxRename')[0]->details['steps'][4]['outcome']);
    }

    public function test_an_externally_authenticated_mailbox_is_renamed_too(): void
    {
        $this->mailcow()->legacyMailbox('ext@example.org', authsource: 'ldap');
        $this->asAdmin();

        $this->rename('ext@example.org', 'external', false)->assertOk()->assertJsonPath('data.authSource', 'ldap');
    }

    public function test_a_login_disabled_mailbox_and_a_domain_administrator(): void
    {
        DB::table('mailbox')->where('username', self::OLD)->update(['active' => 2]);
        $this->asDomainAdmin(['example.org']);

        $this->rename(self::OLD, 'anna.smith', false)->assertOk()->assertJsonPath('data.status', 'loginDisabled');
    }
}
