<?php

namespace Tests\Unit\MailQueue;

use App\Services\MailQueue\PostqueueJsonParser;
use PHPUnit\Framework\TestCase;
use Tests\Support\Monitoring\MonitoringFixtures;

/**
 * Feature 011 T060 (FR-037, research R17, R33): `postqueue -j` output, one JSON object per line.
 */
class PostqueueJsonParserTest extends TestCase
{
    public function test_the_recorded_queue_is_parsed(): void
    {
        $listing = (new PostqueueJsonParser)->parse(MonitoringFixtures::text('postqueue-j.txt'));

        $this->assertCount(8, $listing->messages);
        $this->assertFalse($listing->truncated);
        $this->assertSame(['active' => 2, 'deferred' => 3, 'hold' => 2, 'incoming' => 1, 'maildrop' => 0, 'total' => 8], $listing->counts);
        $this->assertSame([
            'id' => '6B2C3D4E5F',
            'queue' => 'deferred',
            'arrivalTime' => '2025-10-18T09:58:20Z',
            'sizeBytes' => 512,
            'sender' => 'alice@example.org',
            'recipients' => [['address' => 'frank@Partner.Example', 'deferralReason' => 'host mx.partner.example[192.0.2.10] said: 451 4.7.1 Try again later (in reply to RCPT TO command)']],
            'forcedExpiry' => true,
        ], $listing->messages[2]);
        $this->assertSame([['address' => 'gina@other.example', 'deferralReason' => null]], $listing->messages[3]['recipients']);
        $this->assertNull($listing->messages[7]['forcedExpiry'], 'a message without forced_expire reports null');
        $this->assertSame('', $listing->messages[4]['sender'], 'a bounce has an empty sender');
    }

    public function test_empty_lines_the_line_1_and_unparseable_lines_are_skipped(): void
    {
        $output = "1\n\n".'{"queue_name": "hold", "queue_id": "ABC123", "arrival_time": 1760781000, "message_size": 10, "sender": "a@example.org", "recipients": []}'."\nnot json\n".'{"queue_name": "bounce", "queue_id": "DEF456"}'."\n";

        $listing = (new PostqueueJsonParser)->parse($output);

        $this->assertSame(['ABC123'], array_column($listing->messages, 'id'));
        $this->assertSame(1, $listing->counts['total']);
        $this->assertTrue($listing->contains('ABC123'));
        $this->assertFalse($listing->contains('DEF456'));
    }

    public function test_a_queue_of_twelve_thousand_messages_is_truncated_but_fully_counted(): void
    {
        $listing = (new PostqueueJsonParser)->parse(MonitoringFixtures::largeQueue(12000));

        $this->assertCount(10000, $listing->messages);
        $this->assertTrue($listing->truncated);
        $this->assertSame(12000, $listing->counts['total']);
        $this->assertSame(3000, $listing->counts['hold']);
        $this->assertTrue($listing->contains(sprintf('A%05X', 11000)), 'an ID beyond the listing is still known for the actions');
        $this->assertFalse($listing->contains('FFFFFF'));
    }
}
