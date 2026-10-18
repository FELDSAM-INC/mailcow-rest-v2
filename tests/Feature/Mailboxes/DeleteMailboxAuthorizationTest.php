<?php

namespace Tests\Feature\Mailboxes;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\TestCase;

/**
 * Feature 003 T061 (FR-003, FR-004, FR-038, US4 AS-4, AS-5): who may delete mailboxes, and stale
 * versions.
 */
class DeleteMailboxAuthorizationTest extends TestCase
{
    use ApiKeys;
    use MailboxTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
        $this->mailcow()->domain('other.example');
        $this->mailcow()->legacyMailbox('anna@example.org');
        $this->mailcow()->legacyMailbox('carl@other.example');
    }

    public function test_foreign_mailboxes_are_not_found_and_read_only_keys_are_refused(): void
    {
        $this->asDomainAdmin(['example.org']);
        $this->deleteMailbox('carl@other.example')->assertNotFound();
        $this->assertNotNull($this->mailboxRow('carl@other.example'));

        $this->forgetCookies();
        $this->mailcow()->admin('admin', MailcowFactory::PASSWORD);
        $this->deleteJson('/api/v2/mailboxes/anna@example.org', [], $this->keyHeader($this->apiKey('admin', 'readOnly')))
            ->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');
        $this->dockerApi()->assertNothingCalled();
    }

    public function test_an_outdated_version_removes_nothing_and_calls_no_container(): void
    {
        $this->asAdmin();
        $etag = (string) $this->getJson('/api/v2/mailboxes/anna@example.org')->headers->get('ETag');
        DB::table('mailbox')->where('username', 'anna@example.org')->update(['quota' => 1048576]);

        $this->deleteMailbox('anna@example.org', ['If-Match' => $etag])->assertStatus(412);
        $this->assertNotNull($this->mailboxRow('anna@example.org'));
        $this->dockerApi()->assertNothingCalled();
    }

    public function test_mailbox_users_are_refused(): void
    {
        $this->signInAs('anna@example.org', Role::MailboxUser);

        $this->deleteMailbox('anna@example.org')->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');
    }
}
