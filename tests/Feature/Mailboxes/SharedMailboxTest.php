<?php

namespace Tests\Feature\Mailboxes;

use App\Models\Mailcow\SchemaVersion;
use Tests\Support\Auth\AuthRequests;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\TestCase;

class SharedMailboxTest extends TestCase
{
    use AuthRequests;
    use MailboxTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailcow()->domain('example.org');
        $this->asAdmin();
    }

    public function test_shared_mailbox_capability_follows_the_schema_and_old_writes_are_refused(): void
    {
        $modern = SchemaVersion::hasNovember2026Features();
        $this->getJson('/api/v2/capabilities/mailboxes')->assertOk()->assertJsonPath('data.sharedMailboxes', $modern);
        if (! $modern) {
            $this->createMailbox(['localPart' => 'shared', 'domain' => 'example.org', 'shared' => true])->assertUnprocessable();
            $this->assertDatabaseMissing('mailbox', ['username' => 'shared@example.org']);
        }
    }

    private function modern(): void
    {
        if (! SchemaVersion::hasNovember2026Features()) {
            $this->markTestSkipped('Shared mailbox writes require the 2026-11 schema.');
        }
    }

    public function test_shared_creation_needs_no_password_and_keeps_regular_mail_delivery(): void
    {
        $this->modern();
        $response = $this->createMailbox(['localPart' => 'shared', 'domain' => 'example.org', 'shared' => true, 'forcePasswordChange' => true])
            ->assertCreated()->assertJsonPath('data.shared', true)->assertJsonPath('data.status', 'active')->assertJsonPath('data.forcePasswordChange', false);
        $row = $this->mailboxRow('shared@example.org');
        $this->assertNotNull($row);
        $this->assertNotSame('', $row->password);
        $this->assertStringNotContainsString((string) $row->password, $response->getContent());
        $this->assertSame('1', $this->mailboxAttributes('shared@example.org')['shared']);
        $this->assertDatabaseHas('alias', ['address' => 'shared@example.org', 'goto' => 'shared@example.org', 'active' => 1]);
        $this->assertDatabaseHas('mailbox', ['username' => 'shared@example.org', 'kind' => '', 'active' => 1]);
    }

    public function test_converting_an_existing_mailbox_blocks_its_password_and_preserves_other_attributes(): void
    {
        $this->modern();
        $this->mailcow()->mailbox('anna@example.org', 'Passw0rd!dev', attributes: ['imap_access' => '1', 'custom_marker' => 'keep']);
        $this->patchMailbox('anna@example.org', ['shared' => true])->assertOk()->assertJsonPath('data.shared', true);
        $this->assertSame('keep', $this->mailboxAttributes('anna@example.org')['custom_marker']);
        $this->getJson('/api/v2/mailboxes')->assertOk()->assertJsonPath('data.0.shared', true);
        $this->forgetCookies();
        $this->passwordSignIn('anna@example.org', 'Passw0rd!dev')->assertUnauthorized();
    }

    public function test_conversion_is_scoped_audited_and_versioned(): void
    {
        $this->modern();
        $this->mailcow()->mailbox('anna@example.org', 'Passw0rd!dev');
        $etag = $this->getJson('/api/v2/mailboxes/anna@example.org')->json('data.etag');
        $this->patchMailbox('anna@example.org', ['shared' => true], ['If-Match' => $etag])->assertOk();
        $this->patchMailbox('anna@example.org', ['shared' => false], ['If-Match' => $etag])->assertStatus(412);
        $this->assertSame(['old' => false, 'new' => true], $this->auditOf('mailboxUpdate')[0]->details['changes']['shared']);
        $this->mailcow()->domain('other.org');
        $this->forgetCookies();
        $this->asDomainAdmin(['other.org']);
        $this->patchMailbox('anna@example.org', ['shared' => false])->assertNotFound();
        $this->assertSame('1', $this->mailboxAttributes('anna@example.org')['shared']);
    }

    public function test_webmail_on_behalf_refuses_a_shared_mailbox(): void
    {
        $this->modern();
        config(['restv2.allow_admin_email_login' => true, 'restv2.skip_sogo' => false]);
        $this->mailcow()->mailbox('shared@example.org', attributes: ['shared' => '1']);
        $this->postEmpty('/api/v2/mailboxes/shared@example.org/webmail-sign-in', $this->csrf())->assertUnprocessable();
    }
}
