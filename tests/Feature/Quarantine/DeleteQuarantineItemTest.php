<?php

namespace Tests\Feature\Quarantine;

use Illuminate\Support\Facades\DB;
use Tests\Support\Quarantine\QuarantineTestHelpers;
use Tests\TestCase;

/**
 * Feature 006 T032 (FR-024): deleting an item removes it permanently without calling the spam
 * filter or delivering anything, and answers with no body.
 */
class DeleteQuarantineItemTest extends TestCase
{
    use QuarantineTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpamFilter();
        $this->quarantineMailbox('bob@example.org');
        $this->asQuarantineUser('alice@example.org');
    }

    public function test_the_item_is_removed_without_any_call(): void
    {
        $id = $this->item('alice@example.org', 'plain', ['qid' => 'QIDDEL', 'sender' => 'sender@example.net', 'subject' => 'Secret subject']);

        $response = $this->send('DELETE', "/api/v2/quarantine/{$id}")->assertNoContent();

        $this->assertSame('', $response->getContent());
        $this->assertNull($this->itemRow($id));
        $this->spamFilter->assertNoCalls();
        $this->mailer->assertNothingDelivered();
        $records = $this->auditOfTarget('quarantineItem', (string) $id);
        $this->assertCount(1, $records);
        $this->assertSame('quarantineItemDeletion', $records[0]->event_type);
        $this->assertSame(['queueId' => 'QIDDEL', 'recipient' => 'alice@example.org', 'sender' => 'sender@example.net'], $records[0]->details);
        $this->assertStringNotContainsString('Secret subject', (string) DB::table('restv2_audit_events')->value('details'));
    }

    public function test_a_second_delete_is_not_found(): void
    {
        $id = $this->item('alice@example.org');

        $this->send('DELETE', "/api/v2/quarantine/{$id}")->assertNoContent();
        $this->send('DELETE', "/api/v2/quarantine/{$id}")->assertNotFound();
    }

    public function test_without_quarantine_the_delete_is_refused(): void
    {
        DB::table('user_acl')->where('username', 'alice@example.org')->update(['quarantine' => 0]);
        $id = $this->item('alice@example.org');

        $this->send('DELETE', "/api/v2/quarantine/{$id}")->assertForbidden()->assertJsonPath('type', '/api/v2/problems/permission-flag-required');

        $this->assertNotNull($this->itemRow($id));
    }

    public function test_another_mailboxs_item_is_not_found(): void
    {
        $id = $this->item('bob@example.org');

        $this->send('DELETE', "/api/v2/quarantine/{$id}")->assertNotFound();

        $this->assertNotNull($this->itemRow($id));
    }

    public function test_an_outdated_if_match_is_refused(): void
    {
        $id = $this->item('alice@example.org');

        $this->send('DELETE', "/api/v2/quarantine/{$id}", null, ['If-Match' => '"outdated"'])->assertStatus(412);

        $this->assertNotNull($this->itemRow($id));
        $this->assertSame('versionOutdated', $this->auditOfTarget('quarantineItem', (string) $id)[0]->failure_reason);
    }
}
