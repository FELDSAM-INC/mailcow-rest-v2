<?php

namespace Tests\Feature\Mailboxes;

use App\Auth\Role;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\TestCase;

/**
 * Feature 003 T054 (FR-004–FR-006, AS-5, AS-8): who may change mailboxes and which settings a
 * domain administrator may choose.
 */
class UpdateMailboxAuthorizationTest extends TestCase
{
    use ApiKeys;
    use MailboxTestHelpers;

    private const FLAGS = ['unlimited_quota', 'protocol_access', 'sogo_access', 'ratelimit', 'syncjobs', 'quarantine', 'app_passwds', 'pushover', 'spam_policy'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
        $this->mailcow()->domain('other.example');
        $this->mailcow()->legacyMailbox('anna@example.org', quota: self::GIB);
        $this->mailcow()->legacyMailbox('unlimited@example.org', quota: 0);
        $this->mailcow()->legacyMailbox('carl@other.example');
    }

    public function test_mailbox_users_are_refused_even_for_their_own_mailbox(): void
    {
        $this->signInAs('anna@example.org', Role::MailboxUser);

        $this->patchMailbox('anna@example.org', ['displayName' => 'Me'])->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');
    }

    public function test_a_domain_administrator_needs_the_flag_of_each_chosen_setting(): void
    {
        $this->asDomainAdmin(['example.org'], daAcl: array_fill_keys(self::FLAGS, 0));

        $this->patchMailbox('anna@example.org', ['protocolAccess' => ['imap' => false]])->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/attribute-not-permitted')
            ->assertJsonPath('requiredFlags', ['protocolAccess.imap' => 'protocolAccess']);
        $this->patchMailbox('anna@example.org', ['webmailRedirect' => false])->assertStatus(403)->assertJsonPath('requiredFlags.webmailRedirect', 'sogoAccess');
        $this->patchMailbox('anna@example.org', ['webmailRedirect' => true, 'protocolAccess' => ['imap' => true]])->assertOk();
        $this->patchMailbox('anna@example.org', ['quota' => 0])->assertStatus(403)->assertJsonPath('requiredFlags.quota', 'unlimitedQuota');
        $this->patchMailbox('unlimited@example.org', ['quota' => 0, 'displayName' => 'U'])->assertOk();
        $this->patchMailbox('carl@other.example', ['displayName' => 'C'])->assertNotFound();
        $this->assertSame('1', $this->mailboxAttributes('anna@example.org')['imap_access']);
    }

    public function test_an_administrator_may_turn_off_imap(): void
    {
        $this->asAdmin();

        $this->patchMailbox('anna@example.org', ['protocolAccess' => ['imap' => false]])->assertOk();
        $this->assertSame('0', $this->mailboxAttributes('anna@example.org')['imap_access']);
    }

    public function test_keys(): void
    {
        $this->mailcow()->admin('admin', MailcowFactory::PASSWORD);
        $this->mailcow()->domainAdmin('reseller', ['example.org'], array_fill_keys(self::FLAGS, 1));

        $this->patchMerge('/api/v2/mailboxes/anna@example.org', ['displayName' => 'A'], $this->keyHeader($this->apiKey('admin', 'readOnly')))
            ->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');
        $this->patchMerge('/api/v2/mailboxes/anna@example.org', ['displayName' => 'A'], $this->keyHeader($this->apiKey('reseller', name: 'da')))->assertOk();
        $this->patchMerge('/api/v2/mailboxes/carl@other.example', ['displayName' => 'C'], $this->keyHeader($this->apiKey('reseller', name: 'da2')))->assertNotFound();
    }
}
