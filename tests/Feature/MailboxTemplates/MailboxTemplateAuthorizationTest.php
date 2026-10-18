<?php

namespace Tests\Feature\MailboxTemplates;

use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\TestCase;

/**
 * Feature 003 T073 (FR-005, FR-049, US6 AS-1): administrators manage templates, domain
 * administrators read them (without the permissions whose flag they lack), mailbox users never.
 */
class MailboxTemplateAuthorizationTest extends TestCase
{
    use ApiKeys;
    use MailboxTestHelpers;

    private int $id;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
        $this->id = $this->mailcow()->mailboxTemplate('Staff', ['quota' => self::GIB]);
    }

    public function test_domain_administrators_read_but_never_write(): void
    {
        $this->asDomainAdmin(['example.org'], daAcl: ['syncjobs' => 0]);

        $this->getJson('/api/v2/mailbox-templates')->assertOk();
        $this->assertArrayNotHasKey('syncjobs', (array) $this->getJson('/api/v2/mailbox-templates/'.$this->id)->assertOk()->json('data.userPermissions'));
        $this->postJson('/api/v2/mailbox-templates', ['name' => 'Mine'], $this->csrf())->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');
        $this->patchMerge('/api/v2/mailbox-templates/'.$this->id, ['quota' => 0], $this->csrf())->assertStatus(403);
        $this->deleteJson('/api/v2/mailbox-templates/'.$this->id, [], $this->csrf())->assertStatus(403);
        $this->assertSame('permissionDenied', $this->auditOf('mailboxTemplateDeletion')[0]->failure_reason);
    }

    public function test_domain_administrator_keys_cannot_write_either(): void
    {
        $this->mailcow()->domainAdmin('reseller', ['example.org']);

        $this->postJson('/api/v2/mailbox-templates', ['name' => 'Mine'], $this->keyHeader($this->apiKey('reseller')))->assertStatus(403);
    }

    public function test_mailbox_users_are_refused_everything(): void
    {
        $this->asMailboxUser('anna@example.org');

        $this->getJson('/api/v2/mailbox-templates')->assertStatus(403);
        $this->getJson('/api/v2/mailbox-templates/'.$this->id)->assertStatus(403);
        $this->postJson('/api/v2/mailbox-templates', ['name' => 'Mine'], $this->csrf())->assertStatus(403);
        $this->patchMerge('/api/v2/mailbox-templates/'.$this->id, ['quota' => 0], $this->csrf())->assertStatus(403);
        $this->deleteJson('/api/v2/mailbox-templates/'.$this->id, [], $this->csrf())->assertStatus(403);
    }

    public function test_administrator_keys_and_unknown_ids(): void
    {
        $this->mailcow()->admin('admin', MailcowFactory::PASSWORD);
        $readOnly = $this->keyHeader($this->apiKey('admin', 'readOnly'));

        $this->getJson('/api/v2/mailbox-templates/'.$this->id, $readOnly)->assertOk();
        $this->postJson('/api/v2/mailbox-templates', ['name' => 'Mine'], $readOnly)->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');
        $this->postJson('/api/v2/mailbox-templates', ['name' => 'Mine'], $this->keyHeader($this->apiKey('admin', name: 'rw')))->assertCreated();
        $this->getJson('/api/v2/mailbox-templates/999999', $readOnly)->assertNotFound();
    }
}
