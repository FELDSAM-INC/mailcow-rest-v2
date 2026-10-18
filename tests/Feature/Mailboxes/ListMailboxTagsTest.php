<?php

namespace Tests\Feature\Mailboxes;

use App\Auth\Role;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\TestCase;

/**
 * Feature 003 T069 (FR-066, US5 AS-6): the distinct mailbox tags in the caller's scope with their
 * counts, merged without regard to letter case, never revealing tags of other domains.
 */
class ListMailboxTagsTest extends TestCase
{
    use ApiKeys;
    use MailboxTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('a.example');
        $this->mailcow()->domain('b.example');
        $this->mailcow()->legacyMailbox('one@a.example', tags: ['Sales']);
        $this->mailcow()->legacyMailbox('two@a.example', tags: ['sales', 'sales-eu']);
        $this->mailcow()->legacyMailbox('three@a.example', tags: ['Sales']);
        $this->mailcow()->legacyMailbox('secret@b.example', tags: ['Secret', 'Sales']);
        $this->mailcow()->mailbox('room@a.example', kind: 'location');
        $this->mailcow()->mailboxTag('room@a.example', 'Room');
    }

    public function test_a_domain_administrator_sees_the_tags_of_its_mailboxes(): void
    {
        $this->asDomainAdmin(['a.example']);

        $response = $this->getJson('/api/v2/mailbox-tags?q=sal')->assertOk();

        $this->assertSame([['name' => 'Sales', 'mailboxCount' => 3], ['name' => 'sales-eu', 'mailboxCount' => 1]], $response->json('data'));
        $this->assertSame(['total' => 2, 'limit' => 25, 'offset' => 0], $response->json('meta'));
        $this->assertSame(['Sales', 'sales-eu'], array_column((array) $this->getJson('/api/v2/mailbox-tags')->json('data'), 'name'));
    }

    public function test_an_administrator_sees_every_tag_with_paging_and_order(): void
    {
        $this->asAdmin();

        $this->assertSame([['name' => 'Sales', 'mailboxCount' => 4], ['name' => 'sales-eu', 'mailboxCount' => 1], ['name' => 'Secret', 'mailboxCount' => 1]], $this->getJson('/api/v2/mailbox-tags')->assertOk()->json('data'));
        $this->assertSame(['Secret', 'sales-eu'], array_column((array) $this->getJson('/api/v2/mailbox-tags?order=desc&limit=2')->json('data'), 'name'));
        $this->assertSame(['Secret'], array_column((array) $this->getJson('/api/v2/mailbox-tags?offset=2')->json('data'), 'name'));
    }

    public function test_mailbox_users_are_refused_and_read_only_keys_may_read(): void
    {
        $this->mailcow()->admin('admin', MailcowFactory::PASSWORD);
        $this->getJson('/api/v2/mailbox-tags', $this->keyHeader($this->apiKey('admin', 'readOnly')))->assertOk();

        $this->signInAs('one@a.example', Role::MailboxUser);
        $this->getJson('/api/v2/mailbox-tags')->assertStatus(403);
    }
}
