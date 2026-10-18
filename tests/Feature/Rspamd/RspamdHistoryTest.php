<?php

namespace Tests\Feature\Rspamd;

use Tests\Support\Monitoring\MonitoringFixtures;
use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\TestCase;

/**
 * Feature 011 T091 (US6 AS-1, AS-2, FR-033, FR-034, research R16): Rspamd's scan history.
 */
class RspamdHistoryTest extends TestCase
{
    use MonitoringTestHelpers;

    /**
     * @param  array<string, string>  $query
     * @return list<array<string, mixed>>
     */
    private function rows(array $query = []): array
    {
        return (array) $this->getJson('/api/v2/rspamd/history'.($query === [] ? '' : '?'.http_build_query($query)))->assertOk()->json('data');
    }

    public function test_the_newest_entries_with_the_total(): void
    {
        $rows = [];
        for ($i = 0; $i < 1000; $i++) {
            $rows[] = ['unix_time' => 1760781600 - $i, 'action' => 'no action', 'score' => 0.5, 'required_score' => 15, 'symbols' => [], 'size' => 100, 'time_real' => 0.1, 'subject' => "s{$i}", 'rcpt_smtp' => [], 'rcpt_mime' => [], 'sender_smtp' => 'a@example.net'];
        }
        $this->rspamd()->withHistory(['version' => 2, 'rows' => $rows]);
        $this->asAdmin();

        $response = $this->getJson('/api/v2/rspamd/history')->assertOk();

        $response->assertJsonPath('meta', ['total' => 1000, 'limit' => 25, 'offset' => 0]);
        $this->assertSame('s0', $response->json('data.0.subject'));
        $this->rspamd()->assertCalled('/history');
    }

    public function test_a_rejected_message_is_found_by_its_sender(): void
    {
        $this->asAdmin();

        $rows = $this->rows(['q' => 'spammer@example.net']);

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('reject', $row['action']);
        $this->assertEquals(23.45, $row['score']);
        $this->assertEquals(15, $row['requiredScore']);
        $this->assertSame('203.0.113.66', $row['clientAddress']);
        $this->assertSame('Totally Legit <spammer@example.net>', $row['headerSender']);
        $this->assertSame(5432, $row['sizeBytes']);
        $this->assertSame('<spam-0007@example.net>', $row['messageId']);
        $this->assertNull($row['user'], 'Rspamd records "unknown" when no user authenticated');
        $this->assertSame(['FUZZY_DENIED', 'BAYES_SPAM', 'DMARC_POLICY_REJECT', 'R_SPF_FAIL', 'R_DKIM_ALLOW', 'MIME_GOOD', 'ARC_NA', 'RCVD_COUNT_ZERO'], array_column($row['symbols'], 'name'));
        $this->assertSame(['name' => 'R_SPF_FAIL', 'score' => 1, 'options' => ['-all']], $row['symbols'][3]);
        $this->assertSame([], $row['symbols'][6]['options']);
    }

    public function test_filters(): void
    {
        $this->asAdmin();

        $this->assertCount(6, $this->rows(['action' => 'ADD HEADER', 'limit' => '100']));
        $this->assertCount(11, (array) $this->getJson('/api/v2/rspamd/history?action=greylist&action=soft+reject&limit=100')->assertOk()->json('data'));
        $this->assertSame([23.45], array_column($this->rows(['minScore' => '20']), 'score'));
        $this->assertCount(4, $this->rows(['maxScore' => '0']));
        $this->assertCount(3, $this->rows(['minScore' => '0', 'maxScore' => '1']));
        $this->assertCount(3, $this->rows(['from' => '2025-10-18T09:58:00Z', 'to' => '2025-10-18T10:00:00Z']));
        $this->assertSame(['alice@example.org'], array_column($this->rows(['q' => 'ALICE']), 'user'));
    }

    public function test_sorting(): void
    {
        $this->asAdmin();

        $this->assertEquals(23.45, $this->rows(['sort' => 'score', 'order' => 'desc'])[0]['score']);
        $this->assertSame(5432, $this->rows(['sort' => 'size', 'order' => 'desc'])[0]['sizeBytes']);
        $this->assertEquals(0.25, $this->rows(['sort' => 'scanTime', 'order' => 'asc'])[0]['scanTimeRealSeconds']);
        $this->assertSame('Message 29', $this->rows(['order' => 'asc'])[0]['subject']);
    }

    public function test_every_request_reads_the_controller(): void
    {
        $this->asAdmin();

        $this->rows();
        $this->rows();

        $this->rspamd()->assertCalled('/history', 2);
    }

    public function test_an_invalid_score_is_refused(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $this->getJson('/api/v2/rspamd/history?minScore=abc')->assertStatus(422)->assertJsonPath('errors.minScore.0.code', 'invalidType');
    }

    public function test_the_fixture_has_thirty_rows(): void
    {
        $this->assertCount(30, MonitoringFixtures::json('rspamd-history.json')['rows']);
    }
}
