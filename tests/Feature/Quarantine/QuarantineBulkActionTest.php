<?php

namespace Tests\Feature\Quarantine;

use App\Clients\Rspamd\LearnOutcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Quarantine\QuarantineTestHelpers;
use Tests\TestCase;

/**
 * Feature 006 T043 (FR-027, AS-2.2, AS-2.3, AS-2.8): one action for up to 100 items, refused as a
 * whole only for the role, flags or key scope; otherwise each distinct item is processed, audited
 * and reported on its own, with its action report when done.
 */
class QuarantineBulkActionTest extends TestCase
{
    use QuarantineTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpamFilter();
        $this->mailcow()->domain('example.org');
        $this->mailcow()->domain('other.example');
        $this->quarantineMailbox('anna@example.org');
        $this->quarantineMailbox('otto@other.example');
    }

    /**
     * @param  list<array<string, string>>  $targets
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function bulk(string $action, array $targets, array $headers = []): TestResponse
    {
        return $this->send('POST', '/api/v2/quarantine/bulk-actions', ['action' => $action, 'targets' => $targets], $headers);
    }

    public function test_forty_releases_with_one_removed_item(): void
    {
        $this->asAdmin();
        $ids = [];

        for ($i = 0; $i < 40; $i++) {
            $ids[] = $this->item('anna@example.org');
        }

        DB::table('quarantine')->where('id', $ids[7])->delete();

        $response = $this->bulk('release', array_map(fn (int $id): array => ['id' => (string) $id], $ids))->assertOk();

        $results = (array) $response->json('data.results');
        $this->assertCount(40, $results);
        $this->assertSame('notFound', $results[7]['outcome']);
        $this->assertArrayNotHasKey('report', $results[7]);
        $this->assertSame('done', $results[0]['outcome']);
        $this->assertSame('release', $results[0]['report']['action']);
        $this->assertSame(['total' => 40, 'done' => 39, 'notFound' => 1, 'refused' => 0, 'outdated' => 0, 'failed' => 0], $response->json('data.summary'));
        $this->mailer->assertDelivered(39);
        $bulkRequestId = $response->json('data.bulkRequestId');
        $records = DB::table('restv2_audit_events')->where('event_type', 'quarantineItemRelease')->get();
        $this->assertCount(40, $records);
        $this->assertSame(39, $records->where('outcome', 'success')->count());
        $this->assertSame(1, $records->where('outcome', 'failure')->count());
        foreach ($records as $record) {
            $this->assertSame($bulkRequestId, json_decode((string) $record->details, true)['bulkRequestId']);
        }
    }

    public function test_each_action_has_the_single_item_side_effects(): void
    {
        $this->asAdmin();
        $release = $this->item('anna@example.org');
        $quiet = $this->item('anna@example.org');
        $spam = $this->item('anna@example.org');
        $delete = $this->item('anna@example.org');

        $this->bulk('release', [['id' => (string) $release]])->assertOk()->assertJsonPath('data.results.0.report.learning', 'done');
        $this->spamFilter->assertCalls(['/learnham', ['/fuzzyadd', 13]]);
        $this->bulk('releaseWithoutLearning', [['id' => (string) $quiet]])->assertOk()->assertJsonPath('data.results.0.report.action', 'releaseWithoutLearning');
        $this->spamFilter->assertCalls(['/learnham', ['/fuzzyadd', 13]]);
        $this->bulk('learnAsSpam', [['id' => (string) $spam]])->assertOk()->assertJsonPath('data.results.0.report.fuzzy', 'done');
        $this->spamFilter->assertCalls(['/learnham', ['/fuzzyadd', 13], '/learnspam', ['/fuzzyadd', 11]]);
        $response = $this->bulk('delete', [['id' => (string) $delete]])->assertOk()->assertJsonPath('data.results.0.outcome', 'done');
        $this->assertArrayNotHasKey('report', (array) $response->json('data.results.0'));

        $this->mailer->assertDelivered(2);
        foreach ([$release, $quiet, $spam, $delete] as $id) {
            $this->assertNull($this->itemRow($id));
        }

        $this->assertSame(['quarantineItemRelease', 'quarantineItemRelease', 'quarantineItemSpamLearning', 'quarantineItemDeletion'], DB::table('restv2_audit_events')->orderBy('occurred_at')->orderBy('id')->pluck('event_type')->all());
    }

    public function test_an_outdated_version_skips_only_that_target(): void
    {
        $this->asAdmin();
        $a = $this->item('anna@example.org');
        $b = $this->item('anna@example.org');
        $etag = (string) $this->getJson("/api/v2/quarantine/{$b}")->json('data.etag');

        $response = $this->bulk('delete', [['id' => (string) $a, 'version' => '"stale"'], ['id' => (string) $b, 'version' => trim($etag, '"')]])->assertOk();

        $this->assertSame(['outdated', 'done'], array_column((array) $response->json('data.results'), 'outcome'));
        $this->assertNotNull($this->itemRow($a));
        $this->assertNull($this->itemRow($b));
    }

    public function test_a_target_named_twice_is_processed_once(): void
    {
        $this->asAdmin();
        $a = $this->item('anna@example.org');

        $response = $this->bulk('release', [['id' => (string) $a], ['id' => (string) $a]])->assertOk();

        $this->assertCount(1, (array) $response->json('data.results'));
        $this->mailer->assertDelivered(1);
    }

    public function test_a_refused_delivery_and_an_unreachable_spam_filter_are_reported_per_item(): void
    {
        $this->asAdmin();
        $a = $this->item('anna@example.org');
        $b = $this->item('anna@example.org');
        $this->mailer->refuse(550, '550 5.1.1 rejected');

        $this->bulk('release', [['id' => (string) $a]])
            ->assertOk()
            ->assertJsonPath('data.results.0.outcome', 'refused')
            ->assertJsonPath('data.results.0.problem.type', '/api/v2/problems/quarantine-delivery-refused');

        $this->spamFilter->respond('/learnspam', LearnOutcome::failed('down'));
        $this->bulk('learnAsSpam', [['id' => (string) $b]])
            ->assertOk()
            ->assertJsonPath('data.results.0.outcome', 'failed')
            ->assertJsonPath('data.results.0.problem.type', '/api/v2/problems/temporary-failure');

        $this->assertNotNull($this->itemRow($a));
        $this->assertNotNull($this->itemRow($b));
    }

    public function test_a_domain_admin_without_quarantine_is_refused_as_a_whole(): void
    {
        $this->asDomainAdmin(['example.org'], 'reseller', ['quarantine' => 0]);
        $a = $this->item('anna@example.org');

        $this->bulk('delete', [['id' => (string) $a]])->assertForbidden()->assertJsonPath('type', '/api/v2/problems/permission-flag-required')->assertJsonPath('requiredFlags', ['quarantine']);

        $this->assertNotNull($this->itemRow($a));
    }

    public function test_a_read_only_key_is_refused_with_one_record(): void
    {
        $this->mailcow()->admin('admin', 'Passw0rd!', 'SSHA256');
        $a = $this->item('anna@example.org');
        $b = $this->item('anna@example.org');

        $this->bulk('delete', [['id' => (string) $a], ['id' => (string) $b]], $this->keyHeader($this->apiKey('admin', 'readOnly')))
            ->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');

        $records = DB::table('restv2_audit_events')->get();
        $this->assertCount(1, $records);
        $this->assertSame('quarantineItemDeletion', $records[0]->event_type);
        $this->assertNull($records[0]->target_type);
        $this->assertSame([(string) $a, (string) $b], json_decode((string) $records[0]->details, true)['targetIds']);
    }

    public function test_more_than_100_targets_are_refused(): void
    {
        $this->asAdmin();

        $this->bulk('delete', array_map(fn (int $i): array => ['id' => (string) $i], range(1, 101)))
            ->assertStatus(422)
            ->assertJsonPath('errors.targets.0.code', 'tooMany')
            ->assertJsonPath('errors.targets.0.params.max', 100);
    }

    public function test_out_of_scope_items_are_not_found(): void
    {
        $this->asDomainAdmin(['example.org'], 'reseller', ['quarantine' => 1]);
        $mine = $this->item('anna@example.org');
        $foreign = $this->item('otto@other.example');

        $response = $this->bulk('delete', [['id' => (string) $foreign], ['id' => (string) $mine], ['id' => 'abc']])->assertOk();

        $this->assertSame(['notFound', 'done', 'notFound'], array_column((array) $response->json('data.results'), 'outcome'));
        $this->assertNotNull($this->itemRow($foreign));
    }
}
