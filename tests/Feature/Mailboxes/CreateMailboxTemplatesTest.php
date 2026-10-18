<?php

namespace Tests\Feature\Mailboxes;

use Illuminate\Support\Facades\DB;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\TestCase;

/**
 * Feature 003 T039 (FR-022, research R5): values come from the request, then the named template,
 * then the Default template, then the built-in defaults; every template value applies, also the
 * tagged-mail handling and the rate limit.
 */
class CreateMailboxTemplatesTest extends TestCase
{
    use MailboxTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
    }

    private function small(): int
    {
        return $this->mailcow()->mailboxTemplate('Small', [
            'quota' => self::GIB,
            'tags' => ['Student'],
            'tagged_mail_handler' => 'subfolder',
            'quarantine_notification' => 'weekly',
            'quarantine_category' => 'all',
            'rl_frame' => 'h',
            'rl_value' => 25,
            'force_pw_update' => 1,
            'force_tfa' => 0,
            'sogo_access' => 0,
            'active' => 2,
            'tls_enforce_in' => 1,
            'tls_enforce_out' => 0,
            'imap_access' => 1,
            'pop3_access' => 0,
            'smtp_access' => 1,
            'sieve_access' => 1,
            'eas_access' => 0,
            'dav_access' => 1,
            'acl_syncjobs' => 1,
            'acl_spam_alias' => 0,
        ]);
    }

    public function test_every_value_of_the_named_template_applies(): void
    {
        $id = $this->small();
        $this->asAdmin();

        $data = $this->createMailbox(['localPart' => 'anna', 'domain' => 'example.org', 'password' => '{PLAIN}x', 'templateId' => $id])
            ->assertCreated()
            ->assertJsonPath('data.creationReport.rateLimit', 'applied')
            ->assertJsonPath('data.creationReport.taggedMailHandling', 'applied')
            ->json('data');

        $this->assertSame(self::GIB, $data['quota']);
        $this->assertSame('loginDisabled', $data['status']);
        $this->assertSame(['Student'], $data['tags']);
        $this->assertTrue($data['forcePasswordChange']);
        $this->assertFalse($data['webmailRedirect']);
        $this->assertTrue($data['tlsEnforceInbound']);
        $this->assertSame(['imap' => true, 'pop3' => false, 'smtp' => true, 'sieve' => true, 'activeSync' => false, 'dav' => true], $data['protocolAccess']);
        $this->assertTrue($data['userPermissions']['syncjobs']);
        $this->assertFalse($data['userPermissions']['spamAlias']);
        $this->assertTrue($data['userPermissions']['pwReset'], 'A permission the template lacks comes from the defaults.');
        $this->assertSame('weekly', $data['quarantineNotification']);
        $this->assertSame('all', $data['quarantineCategory']);
        $this->assertSame('subfolder', $data['taggedMailHandling']);
        $this->assertSame(['value' => 25, 'frame' => 'hour'], $data['rateLimit']);
        $this->assertSame('25 / 1h', $this->redis()->hGet('RL_VALUE', 'anna@example.org'));
        $this->assertSame('1', $this->redis()->hGet('RCPT_WANTS_SUBFOLDER_TAG', 'anna@example.org'));
        $this->assertSame($id, $this->auditOf('mailboxCreation')[0]->details['templateId']);
    }

    public function test_request_values_override_the_template(): void
    {
        $id = $this->small();
        $this->asAdmin();

        $data = $this->createMailbox([
            'localPart' => 'anna', 'domain' => 'example.org', 'password' => '{PLAIN}x', 'templateId' => $id,
            'quota' => 2 * self::GIB, 'status' => 'active', 'protocolAccess' => ['pop3' => true], 'rateLimit' => null, 'tags' => [],
        ])->assertCreated()->assertJsonPath('data.creationReport.rateLimit', 'notRequested')->json('data');

        $this->assertSame(2 * self::GIB, $data['quota']);
        $this->assertSame('active', $data['status']);
        $this->assertTrue($data['protocolAccess']['pop3']);
        $this->assertFalse($data['protocolAccess']['activeSync'], 'Protocols not sent keep the template value.');
        $this->assertNull($data['rateLimit']);
        $this->assertSame([], $data['tags']);
        $this->assertFalse($this->redis()->hExists('RL_VALUE', 'anna@example.org'));
    }

    public function test_values_a_template_lacks_come_from_the_default_template_then_the_built_in_defaults(): void
    {
        $this->mailcow()->defaultMailboxTemplate(['quarantine_notification' => 'daily', 'tagged_mail_handler' => 'subject']);
        $id = $this->mailcow()->mailboxTemplate('Partial', ['quota' => self::GIB, 'pop3_access' => 0]);
        $this->asAdmin();

        $data = $this->createMailbox(['localPart' => 'anna', 'domain' => 'example.org', 'password' => '{PLAIN}x', 'templateId' => $id])->assertCreated()->json('data');

        $this->assertSame('daily', $data['quarantineNotification']);
        $this->assertSame('subject', $data['taggedMailHandling']);
        $this->assertFalse($data['protocolAccess']['pop3']);
        $this->assertTrue($data['userPermissions']['pwReset'], 'The seeded Default template lacks acl_pw_reset.');
        $this->assertFalse($data['userPermissions']['syncjobs']);
    }

    public function test_without_a_named_template_the_default_template_applies_except_its_quota(): void
    {
        $this->mailcow()->defaultMailboxTemplate(['pop3_access' => 0, 'quota' => 0]);
        $this->asAdmin();

        $data = $this->createMailbox(['localPart' => 'anna', 'domain' => 'example.org', 'password' => '{PLAIN}x'])->assertCreated()->json('data');

        $this->assertFalse($data['protocolAccess']['pop3']);
        $this->assertSame(3072 * self::MIB, $data['quota'], 'The domain default, not the Default template\'s unlimited quota.');
        $this->assertNull($this->auditOf('mailboxCreation')[0]->details['templateId']);
    }

    public function test_without_any_template_the_built_in_defaults_apply_and_follow_the_server_setting(): void
    {
        config(['restv2.vars_overrides.MAILBOX_DEFAULT_ATTRIBUTES' => '{"sogo_access":false,"quarantine_notification":"never"}']);
        $this->asAdmin();

        $data = $this->createMailbox(['localPart' => 'anna', 'domain' => 'example.org', 'password' => '{PLAIN}x'])->assertCreated()->json('data');

        $this->assertFalse($data['webmailRedirect']);
        $this->assertSame('never', $data['quarantineNotification']);
        $this->assertSame('active', $data['status']);
        $this->assertSame('rejected', $data['quarantineCategory']);
        $this->assertSame('0', $this->mailboxAttributes('anna@example.org')['sogo_access']);
    }

    public function test_a_missing_template_is_refused(): void
    {
        $this->asAdmin();
        $domainTemplate = DB::table('templates')->insertGetId(['template' => 'Domains', 'type' => 'domain', 'attributes' => '{}']);

        $this->createMailbox(['localPart' => 'anna', 'domain' => 'example.org', 'password' => '{PLAIN}x', 'templateId' => (int) $domainTemplate])
            ->assertStatus(422)->assertJsonPath('errors.templateId.0.code', 'notFound');
    }
}
