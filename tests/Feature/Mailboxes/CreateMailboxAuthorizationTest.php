<?php

namespace Tests\Feature\Mailboxes;

use App\Auth\Role;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\TestCase;

/**
 * Feature 003 T043 (FR-004, FR-005): who may create mailboxes, by session and by key.
 */
class CreateMailboxAuthorizationTest extends TestCase
{
    use ApiKeys;
    use MailboxTestHelpers;

    private const BODY = ['localPart' => 'anna', 'domain' => 'example.org', 'password' => '{PLAIN}x'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
    }

    public function test_mailbox_users_and_read_only_keys_are_refused(): void
    {
        $this->asMailboxUser('user@example.org');
        $this->createMailbox(self::BODY)->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->forgetCookies();
        $this->mailcow()->admin('admin', MailcowFactory::PASSWORD);
        $this->postJson('/api/v2/mailboxes', self::BODY, $this->keyHeader($this->apiKey('admin', 'readOnly')))
            ->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');
        $this->assertNull($this->mailboxRow('anna@example.org'));
    }

    public function test_read_write_keys_of_administrators_and_domain_administrators_create(): void
    {
        $this->mailcow()->admin('admin', MailcowFactory::PASSWORD);
        $this->mailcow()->domainAdmin('reseller', ['example.org']);

        $this->postJson('/api/v2/mailboxes', self::BODY, $this->keyHeader($this->apiKey('admin')))->assertCreated();
        // A quota, since the built-in unlimited one needs the `unlimitedQuota` flag.
        $this->postJson('/api/v2/mailboxes', ['localPart' => 'bob', 'quota' => 1073741824] + self::BODY, $this->keyHeader($this->apiKey('reseller', name: 'da')))->assertCreated();
    }

    public function test_restricted_sessions_and_missing_tokens_are_refused(): void
    {
        $this->mailcow()->admin('restricted', MailcowFactory::PASSWORD, attributes: ['force_pw_update' => '1']);
        $this->signInAs('restricted', Role::Admin);
        $this->createMailbox(self::BODY)->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/pending-action-required');

        $this->forgetCookies();
        $this->asAdmin();
        $this->postJson('/api/v2/mailboxes', self::BODY)->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/csrf-token-mismatch');
        $this->assertNull($this->mailboxRow('anna@example.org'));
    }
}
