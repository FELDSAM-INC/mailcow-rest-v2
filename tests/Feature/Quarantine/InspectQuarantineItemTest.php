<?php

namespace Tests\Feature\Quarantine;

use Illuminate\Support\Facades\DB;
use Tests\Support\Quarantine\LargeMessage;
use Tests\Support\Quarantine\QuarantineTestHelpers;
use Tests\TestCase;

/**
 * Feature 006 T028 (FR-012 to FR-015, AS-1.2, AS-1.7, AS-1.8, AS-1.9): one item with the spam
 * filter's results and its parsed content, never HTML; attachment details only with the right to
 * download them; the authenticated sender only for administrators; messages above the content size
 * limit unparsed; another mailbox's item answers like an unknown one; reading is not audited.
 */
class InspectQuarantineItemTest extends TestCase
{
    use QuarantineTestHelpers;

    private const SYMBOLS = [
        ['name' => 'VIRUS_FOUND', 'score' => 2000, 'options' => ['Eicar-Test-Signature']],
        ['name' => 'BAYES_SPAM', 'score' => 5.1, 'options' => ['99.99%']],
        ['name' => 'R_DKIM_NA'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpamFilter();
        $this->quarantineMailbox('bob@example.org');
    }

    private function virusItem(string $rcpt = 'alice@example.org'): int
    {
        return $this->item($rcpt, 'multipart-two-attachments', [
            'qid' => '4XyZ1q2w3eR',
            'score' => 2015.5,
            'action' => 'reject',
            'symbols' => self::SYMBOLS,
            'fuzzyHashes' => ['1:abcd:efgh', '1:ijkl:mnop'],
            'ip' => '192.0.2.25',
            'user' => 'sender@example.net',
            'sender' => 'sender@example.net',
        ]);
    }

    public function test_a_virus_item_shows_the_filters_results_and_its_content(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $id = $this->virusItem();

        $response = $this->getJson("/api/v2/quarantine/{$id}")->assertOk();
        $data = (array) $response->json('data');

        $this->assertSame(2015.5, $data['score']);
        $this->assertSame('rejected', $data['action']);
        $this->assertTrue($data['virus']);
        $this->assertSame([
            ['name' => 'VIRUS_FOUND', 'score' => 2000, 'options' => ['Eicar-Test-Signature']],
            ['name' => 'BAYES_SPAM', 'score' => 5.1, 'options' => ['99.99%']],
            ['name' => 'R_DKIM_NA', 'score' => 0, 'options' => []],
        ], $data['symbols']);
        $this->assertSame(['1:abcd:efgh', '1:ijkl:mnop'], $data['fuzzyHashes']);
        $this->assertSame('192.0.2.25', $data['sourceAddress']);
        $this->assertSame(strlen(LargeMessage::fixture('multipart-two-attachments')), $data['size']);
        $this->assertSame('available', $data['contentStatus']);
        $this->assertSame('Sender <sender@example.net>', $data['content']['from']);
        $this->assertSame('Invoice and logo', $data['content']['subject']);
        $this->assertSame([['address' => 'alice@example.org', 'source' => 'to'], ['address' => 'alice@example.org', 'source' => 'envelope']], $data['content']['recipients']);
        $this->assertStringContainsString('Please find the invoice attached.', (string) $data['content']['textPlain']);
        $this->assertStringContainsString('Please find the invoice attached.', $data['content']['textFromHtml']);
        $this->assertStringNotContainsString('<b>', $data['content']['textFromHtml']);
        $this->assertCount(2, $data['content']['attachments']);
        $this->assertSame(['invoice.pdf', 'logo.png'], array_column($data['content']['attachments'], 'fileName'));
        $this->assertArrayNotHasKey('authenticatedSender', $data);
        $response->assertHeader('ETag', $data['etag']);
    }

    public function test_attachments_are_null_without_the_attachment_right(): void
    {
        $this->asQuarantineUser('alice@example.org', ['quarantine' => 1, 'quarantine_attachments' => 0]);
        $id = $this->virusItem();

        $data = (array) $this->getJson("/api/v2/quarantine/{$id}")->assertOk()->json('data');

        $this->assertNull($data['content']['attachments']);
        $this->assertSame('available', $data['contentStatus']);
    }

    public function test_an_admin_sees_the_authenticated_sender(): void
    {
        $this->asAdmin();
        $id = $this->virusItem();
        $none = $this->item('bob@example.org');

        $this->assertSame('sender@example.net', $this->getJson("/api/v2/quarantine/{$id}")->assertOk()->json('data.authenticatedSender'));
        $this->assertNull($this->getJson("/api/v2/quarantine/{$none}")->assertOk()->json('data.authenticatedSender'));
    }

    public function test_a_message_above_the_content_limit_is_not_parsed(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $id = $this->item('alice@example.org', overrides: ['msg' => LargeMessage::build(), 'symbols' => self::SYMBOLS]);

        $data = (array) $this->getJson("/api/v2/quarantine/{$id}")->assertOk()->json('data');

        $this->assertSame('tooLarge', $data['contentStatus']);
        $this->assertNull($data['content']);
        $this->assertSame(LargeMessage::LIMIT + 1, $data['size']);
        $this->assertCount(3, $data['symbols']);
    }

    public function test_an_item_without_a_stored_message_is_missing(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $id = $this->item('alice@example.org', overrides: ['msg' => null]);

        $data = (array) $this->getJson("/api/v2/quarantine/{$id}")->assertOk()->json('data');

        $this->assertSame('missing', $data['contentStatus']);
        $this->assertNull($data['content']);
        $this->assertSame(0, $data['size']);
    }

    public function test_another_mailboxs_item_answers_like_an_unknown_one(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $bobs = $this->virusItem('bob@example.org');

        $foreign = $this->getJson("/api/v2/quarantine/{$bobs}")->assertNotFound();
        $unknown = $this->getJson('/api/v2/quarantine/999999')->assertNotFound();

        $this->assertSame($unknown->json('type'), $foreign->json('type'));
        $this->assertSame($unknown->json('detail'), $foreign->json('detail'));
    }

    public function test_reading_writes_no_audit_record(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $id = $this->virusItem();

        $this->getJson("/api/v2/quarantine/{$id}")->assertOk();
        $this->getJson('/api/v2/quarantine')->assertOk();

        $this->assertSame(0, DB::table('restv2_audit_events')->count());
    }
}
