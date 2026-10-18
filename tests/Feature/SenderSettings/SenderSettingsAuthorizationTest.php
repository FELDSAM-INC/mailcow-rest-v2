<?php

namespace Tests\Feature\SenderSettings;

use App\Auth\Role;
use Tests\Support\Aliases\AliasTestHelpers;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\TestCase;

/**
 * Feature 004 T059 (FR-001–FR-005, US5): the sender settings operations for every role and key.
 */
class SenderSettingsAuthorizationTest extends TestCase
{
    use AliasTestHelpers;
    use ApiKeys;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('foreign.example');
        $this->mailbox('anna@example.org');
        $this->mailbox('carl@foreign.example');
        $f->admin('admin', 'Passw0rd!', 'SSHA256');
        $f->domainAdmin('reseller', ['example.org'], scheme: 'SSHA256');
    }

    public function test_read_only_keys_read_and_are_refused_on_changes(): void
    {
        $key = $this->keyHeader($this->apiKey('reseller', 'readOnly'));

        $this->getJson('/api/v2/mailboxes/anna@example.org/sender-settings', $key)->assertOk();
        $this->getJson('/api/v2/sender-permission-candidates?mailbox=anna@example.org', $key)->assertOk();
        $this->patchMerge('/api/v2/mailboxes/anna@example.org/sender-settings', ['explicitPermissions' => []], $key)->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');
        $this->assertSame('permissionDenied', $this->auditOf('mailboxSenderSettingsUpdate')[0]->failure_reason);
    }

    public function test_foreign_mailboxes_are_not_found_and_mailbox_users_forbidden(): void
    {
        $key = $this->keyHeader($this->apiKey('reseller'));
        $this->getJson('/api/v2/mailboxes/carl@foreign.example/sender-settings', $key)->assertNotFound();
        $this->patchMerge('/api/v2/mailboxes/carl@foreign.example/sender-settings', ['explicitPermissions' => []], $key)->assertNotFound();
        $this->patchMerge('/api/v2/mailboxes/anna@example.org/sender-settings', ['explicitPermissions' => ['carl@foreign.example']], $key)->assertStatus(422);
        $this->assertSame(['notFound', 'validationFailed'], array_column($this->auditOf('mailboxSenderSettingsUpdate'), 'failure_reason'));

        $this->signInAs('anna@example.org', Role::MailboxUser);
        $this->getJson('/api/v2/mailboxes/anna@example.org/sender-settings')->assertForbidden();
        $this->getJson('/api/v2/sender-permission-candidates?mailbox=anna@example.org')->assertForbidden();
        $this->patchMerge('/api/v2/mailboxes/anna@example.org/sender-settings', ['explicitPermissions' => []], $this->csrf())->assertForbidden();

        $this->signInAs('admin', Role::Admin);
        $this->patchMerge('/api/v2/mailboxes/carl@foreign.example/sender-settings', ['explicitPermissions' => ['*']], $this->csrf())->assertOk();
    }
}
