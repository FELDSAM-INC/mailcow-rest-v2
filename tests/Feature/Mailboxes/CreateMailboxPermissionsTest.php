<?php

namespace Tests\Feature\Mailboxes;

use Illuminate\Support\Facades\DB;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\TestCase;

/**
 * Feature 003 T040 (FR-006, AS-5, AS-6): a domain administrator may choose a gated setting at
 * creation only with its flag; template values still apply, except an unlimited quota. A refusal
 * names each field with its flag, writes nothing and is audited.
 */
class CreateMailboxPermissionsTest extends TestCase
{
    use MailboxTestHelpers;

    private const FLAGS = ['unlimited_quota', 'protocol_access', 'sogo_access', 'ratelimit', 'syncjobs', 'quarantine', 'app_passwds', 'pushover', 'spam_policy'];

    private int $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
        $this->template = $this->mailcow()->mailboxTemplate('Pop', ['quota' => self::GIB, 'pop3_access' => 1, 'acl_syncjobs' => 1]);
    }

    private function asResellerLacking(string ...$flags): void
    {
        $this->asDomainAdmin(['example.org'], daAcl: array_fill_keys(array_diff(self::FLAGS, $flags), 1) + array_fill_keys($flags, 0));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function refusal(array $body): array
    {
        return (array) $this->createMailbox(['localPart' => 'anna', 'domain' => 'example.org', 'password' => '{PLAIN}x'] + $body)
            ->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/attribute-not-permitted')
            ->json();
    }

    public function test_an_unlimited_quota_needs_the_flag_also_from_a_template(): void
    {
        $unlimited = $this->mailcow()->mailboxTemplate('Unlimited', ['quota' => 0]);
        $this->asResellerLacking('unlimited_quota');

        $this->assertSame(['quota' => 'unlimitedQuota'], $this->refusal(['quota' => 0])['requiredFlags']);
        $this->assertSame(['quota' => 'unlimitedQuota'], $this->refusal(['templateId' => $unlimited])['requiredFlags']);
        $this->createMailbox(['localPart' => 'anna', 'domain' => 'example.org', 'password' => '{PLAIN}x', 'templateId' => $unlimited, 'quota' => self::GIB])->assertCreated();
    }

    public function test_protocol_access_needs_the_flag_unless_it_equals_the_template(): void
    {
        $this->asResellerLacking('protocol_access');

        $problem = $this->refusal(['templateId' => $this->template, 'protocolAccess' => ['pop3' => false]]);
        $this->assertSame(['protocolAccess.pop3'], $problem['attributes']);
        $this->assertSame(['protocolAccess.pop3' => 'protocolAccess'], $problem['requiredFlags']);
        $this->assertSame(0, DB::table('mailbox')->count());

        $this->createMailbox(['localPart' => 'anna', 'domain' => 'example.org', 'password' => '{PLAIN}x', 'templateId' => $this->template, 'protocolAccess' => ['pop3' => true]])->assertCreated();
        $this->createMailbox(['localPart' => 'bob', 'domain' => 'example.org', 'password' => '{PLAIN}x', 'templateId' => $this->template])->assertCreated()->assertJsonPath('data.protocolAccess.pop3', true);
    }

    public function test_webmail_redirect_rate_limit_and_overlapping_permissions_need_their_flags(): void
    {
        $this->asResellerLacking('sogo_access', 'ratelimit', 'syncjobs', 'quarantine', 'app_passwds', 'pushover', 'spam_policy');

        $problem = $this->refusal([
            'webmailRedirect' => false,
            'rateLimit' => ['value' => 10, 'frame' => 'minute'],
            'userPermissions' => ['syncjobs' => true, 'quarantine' => false, 'appPasswds' => false, 'pushover' => false, 'spamPolicy' => false, 'spamScore' => false],
        ]);

        $this->assertSame([
            'rateLimit' => 'ratelimit',
            'userPermissions.appPasswds' => 'appPasswds',
            'userPermissions.pushover' => 'pushover',
            'userPermissions.quarantine' => 'quarantine',
            'userPermissions.spamPolicy' => 'spamPolicy',
            'userPermissions.syncjobs' => 'syncjobs',
            'webmailRedirect' => 'sogoAccess',
        ], $problem['requiredFlags']);

        $record = $this->auditOf('mailboxCreation')[0];
        $this->assertSame(['failure', 'attributeNotPermitted', 'anna@example.org'], [$record->outcome, $record->failure_reason, $record->target_id]);
        $this->assertSame('/api/v2/problems/attribute-not-permitted', $record->details['problemType']);
    }

    public function test_template_values_apply_although_the_flag_is_missing(): void
    {
        $this->asResellerLacking('syncjobs', 'protocol_access');

        $this->createMailbox(['localPart' => 'anna', 'domain' => 'example.org', 'password' => '{PLAIN}x', 'templateId' => $this->template, 'userPermissions' => ['syncjobs' => true, 'spamScore' => false]])
            ->assertCreated();

        $this->assertSame(1, (int) DB::table('user_acl')->where('username', 'anna@example.org')->value('syncjobs'));
        $this->assertSame(0, (int) DB::table('user_acl')->where('username', 'anna@example.org')->value('spam_score'));
    }

    public function test_with_every_flag_everything_may_be_chosen(): void
    {
        $this->asResellerLacking();

        $this->createMailbox([
            'localPart' => 'anna', 'domain' => 'example.org', 'password' => '{PLAIN}x', 'quota' => 0,
            'protocolAccess' => ['pop3' => false], 'webmailRedirect' => false, 'rateLimit' => ['value' => 5, 'frame' => 'second'],
            'userPermissions' => ['syncjobs' => true],
        ])->assertCreated();
    }
}
