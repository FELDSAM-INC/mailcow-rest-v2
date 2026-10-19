<?php

namespace Tests\Feature\Quarantine;

use Carbon\CarbonImmutable;
use Tests\Support\Quarantine\QuarantineTestHelpers;
use Tests\TestCase;

/**
 * Feature 006 T027 (FR-001, FR-007, FR-008, AS-1.1): a mailbox user lists the quarantine items of
 * their own mailbox, 25 per page and newest first, whatever their flags. Neither the stored message
 * nor the quick-action hash is ever returned.
 */
class ListOwnQuarantineTest extends TestCase
{
    use QuarantineTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpamFilter();
        $this->quarantineMailbox('alice@example.org', self::NO_USER_FLAGS);
        $this->quarantineMailbox('bob@example.org');
    }

    public function test_a_mailbox_user_gets_their_25_newest_items_without_any_flag(): void
    {
        $start = CarbonImmutable::parse('2026-10-01 10:00:00');
        $ids = [];

        for ($i = 0; $i < 30; $i++) {
            $ids[] = $this->item('alice@example.org', overrides: ['created' => $start->addMinutes($i)->format('Y-m-d H:i:s')]);
        }

        for ($i = 0; $i < 5; $i++) {
            $this->item('bob@example.org', overrides: ['created' => $start->addHours(5)->format('Y-m-d H:i:s')]);
        }

        $this->asQuarantineUser('alice@example.org');
        $response = $this->getJson('/api/v2/quarantine')->assertOk();

        $this->assertSame(['total' => 30, 'limit' => 25, 'offset' => 0], $response->json('meta'));
        $this->assertSame(array_slice(array_reverse($ids), 0, 25), array_column((array) $response->json('data'), 'id'));
        $this->assertSame(['alice@example.org'], array_values(array_unique(array_column((array) $response->json('data'), 'recipient'))));

        $second = $this->getJson('/api/v2/quarantine?offset=25')->assertOk();
        $this->assertSame(array_slice(array_reverse($ids), 25), array_column((array) $second->json('data'), 'id'));
    }

    public function test_each_entry_has_every_member(): void
    {
        $id = $this->item('alice@example.org', overrides: [
            'qid' => '4XyZ1q2w3eR',
            'subject' => null,
            'score' => null,
            'action' => 'add header',
            'sender' => 'sender@example.net',
            'notified' => 1,
            'created' => '2026-10-01 10:00:00',
        ]);

        $this->asQuarantineUser('alice@example.org');
        $entry = $this->getJson('/api/v2/quarantine')->assertOk()->json('data.0');

        $this->assertSame($id, $entry['id']);
        $this->assertSame('4XyZ1q2w3eR', $entry['queueId']);
        $this->assertSame('', $entry['subject']);
        $this->assertSame('sender@example.net', $entry['sender']);
        $this->assertSame('alice@example.org', $entry['recipient']);
        $this->assertNull($entry['score']);
        $this->assertSame('junk', $entry['action']);
        $this->assertSame('add header', $entry['filterAction']);
        $this->assertFalse($entry['virus']);
        $this->assertTrue($entry['notified']);
        $this->assertSame('2026-10-01T08:00:00Z', $entry['arrivedAt']);
        $this->assertMatchesRegularExpression('/^"[A-Za-z0-9_-]+"$/', $entry['etag']);
    }

    public function test_actions_are_mapped_and_the_virus_flag_comes_from_the_symbols(): void
    {
        $this->item('alice@example.org', overrides: ['action' => 'reject', 'score' => 2015.5, 'symbols' => [['name' => 'VIRUS_FOUND', 'score' => 2000, 'options' => ['Eicar-Test-Signature']]], 'created' => '2026-10-01 10:00:00']);
        $this->item('alice@example.org', overrides: ['action' => 'rewrite subject', 'created' => '2026-10-01 10:01:00']);
        $this->item('alice@example.org', overrides: ['action' => 'foo', 'created' => '2026-10-01 10:02:00']);

        $this->asQuarantineUser('alice@example.org');
        $data = (array) $this->getJson('/api/v2/quarantine')->assertOk()->json('data');

        $this->assertSame(['other', 'subjectRewritten', 'rejected'], array_column($data, 'action'));
        $this->assertSame(['foo', 'rewrite subject', 'reject'], array_column($data, 'filterAction'));
        $this->assertSame([false, false, true], array_column($data, 'virus'));
        $this->assertSame(2015.5, $data[2]['score']);
    }

    public function test_neither_the_stored_message_nor_the_quick_action_hash_is_returned(): void
    {
        $id = $this->item('alice@example.org', 'spam-flag');
        $row = $this->itemRow($id);
        $this->assertNotNull($row);

        $this->asQuarantineUser('alice@example.org');
        $body = (string) $this->getJson('/api/v2/quarantine')->assertOk()->getContent();

        $this->assertStringNotContainsString('qhash', $body);
        $this->assertStringNotContainsString((string) $row->qhash, $body);
        $this->assertStringNotContainsString('"msg"', $body);
        $this->assertStringNotContainsString('Buy now', $body);
    }
}
