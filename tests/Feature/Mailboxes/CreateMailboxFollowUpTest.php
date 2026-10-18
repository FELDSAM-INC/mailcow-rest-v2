<?php

namespace Tests\Feature\Mailboxes;

use Illuminate\Support\Facades\DB;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\TestCase;

/**
 * Feature 003 T042 (FR-027, AS-13): after the atomic save, the rate limit, the tagged-mail handling
 * and the groupware entry are follow-up steps whose outcomes are reported and audited; a failing
 * step never removes the mailbox.
 */
class CreateMailboxFollowUpTest extends TestCase
{
    use MailboxTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
    }

    public function test_tagged_mail_handling_is_stored_as_legacy_does(): void
    {
        $this->asAdmin();

        $this->createMailbox(['localPart' => 'subject', 'domain' => 'example.org', 'password' => '{PLAIN}x', 'taggedMailHandling' => 'subject'])
            ->assertCreated()->assertJsonPath('data.creationReport.taggedMailHandling', 'applied')->assertJsonPath('data.taggedMailHandling', 'subject');
        $this->createMailbox(['localPart' => 'folder', 'domain' => 'example.org', 'password' => '{PLAIN}x', 'taggedMailHandling' => 'subfolder'])
            ->assertCreated()->assertJsonPath('data.creationReport.taggedMailHandling', 'applied');
        $this->createMailbox(['localPart' => 'none', 'domain' => 'example.org', 'password' => '{PLAIN}x', 'taggedMailHandling' => 'none'])
            ->assertCreated()->assertJsonPath('data.creationReport.taggedMailHandling', 'notRequested');

        $this->assertSame('1', $this->redis()->hGet('RCPT_WANTS_SUBJECT_TAG', 'subject@example.org'));
        $this->assertFalse($this->redis()->hExists('RCPT_WANTS_SUBFOLDER_TAG', 'subject@example.org'));
        $this->assertSame('1', $this->redis()->hGet('RCPT_WANTS_SUBFOLDER_TAG', 'folder@example.org'));
        $this->assertFalse($this->redis()->hExists('RCPT_WANTS_SUBJECT_TAG', 'folder@example.org'));
        $this->assertFalse($this->redis()->hExists('RCPT_WANTS_SUBJECT_TAG', 'none@example.org'));
    }

    public function test_the_groupware_entry_follows_the_status_and_the_groupware_setting(): void
    {
        $this->asAdmin();

        $this->createMailbox(['localPart' => 'active', 'domain' => 'example.org', 'password' => '{PLAIN}x'])
            ->assertCreated()->assertJsonPath('data.creationReport.groupwareDirectoryUpdate', 'done');
        $this->assertTrue(DB::table('_sogo_static_view')->where('c_uid', 'active@example.org')->exists());
        $this->assertSame(1, $this->memcached()->flushCount());

        $this->createMailbox(['localPart' => 'inactive', 'domain' => 'example.org', 'password' => '{PLAIN}x', 'status' => 'inactive'])
            ->assertCreated()->assertJsonPath('data.creationReport.groupwareDirectoryUpdate', 'notRequired');
        $this->assertFalse(DB::table('_sogo_static_view')->where('c_uid', 'inactive@example.org')->exists());
        $this->assertSame(1, $this->memcached()->flushCount());

        config(['restv2.skip_sogo' => true]);
        $this->createMailbox(['localPart' => 'skipped', 'domain' => 'example.org', 'password' => '{PLAIN}x'])
            ->assertCreated()->assertJsonPath('data.creationReport.groupwareDirectoryUpdate', 'groupwareDisabled');
        $this->assertFalse(DB::table('_sogo_static_view')->where('c_uid', 'skipped@example.org')->exists());
    }

    public function test_a_failed_rate_limit_step_keeps_the_mailbox(): void
    {
        $this->asAdmin();
        $csrf = $this->csrf();
        $this->breakSharedStore();

        $response = $this->postJson('/api/v2/mailboxes', [
            'localPart' => 'anna', 'domain' => 'example.org', 'password' => '{SSHA512}c29tZWhhc2g=', 'rateLimit' => ['value' => 10, 'frame' => 'hour'],
        ], $csrf)->assertCreated();

        $this->assertSame('failed', $response->json('data.creationReport.rateLimit'));
        $this->assertNotEmpty($response->json('data.creationReport.messages.rateLimit'));
        $this->assertSame('notRequested', $response->json('data.creationReport.taggedMailHandling'));
        $this->assertNotNull($this->mailboxRow('anna@example.org'));
        $this->assertSame($response->json('data.creationReport'), $this->auditOf('mailboxCreation')[0]->details['creationReport']);
    }

    public function test_an_external_source_is_created_while_the_shared_store_is_down(): void
    {
        $this->mailcow()->identityProvider('ldap');
        $this->asAdmin();
        $csrf = $this->csrf();
        $this->breakSharedStore();

        $this->postJson('/api/v2/mailboxes', ['localPart' => 'anna', 'domain' => 'example.org', 'authSource' => 'ldap', 'taggedMailHandling' => 'subject'], $csrf)
            ->assertCreated()
            ->assertJsonPath('data.creationReport.taggedMailHandling', 'failed');
    }
}
