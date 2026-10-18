<?php

namespace Tests\Feature\SyncJobs;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\SyncJobs\SyncJobTestHelpers;
use Tests\TestCase;

/**
 * Feature 008 T022 (FR-011–FR-015, US1 AS-1, AS-2, AS-5, AS-8): the sync-job list is paged,
 * sortable, filterable and searchable within the caller's scope; every entry carries its version
 * tag and run status, but never the remote password or the log.
 */
class ListSyncJobsTest extends TestCase
{
    use ApiKeys;
    use SyncJobTestHelpers;

    public function test_a_mailbox_user_lists_their_own_jobs_with_run_status_and_no_password(): void
    {
        $this->syncMailbox('bob@example.org');
        $f = $this->mailcow();
        $mine = [
            $f->syncJob('alice@example.org', ['user1' => 'a1']),
            $f->syncJob('alice@example.org', ['user1' => 'a2', 'success' => 1, 'exit_status' => 'EX_OK', 'last_run' => '2026-10-18 09:00:00']),
        ];
        $this->asSyncUser('alice@example.org');
        $mine[] = $f->syncJob('alice@example.org', ['user1' => 'a3', 'is_running' => 1, 'password1' => 'other-secret']);
        $f->syncJob('bob@example.org');

        $response = $this->getJson('/api/v2/sync-jobs')->assertOk();

        $this->assertSame($mine, $this->idsOf($response));
        $this->assertSame(3, $response->json('meta.total'));
        $this->assertSame('imap.example.net', $response->json('data.0.remoteHost'));
        $this->assertSame(993, $response->json('data.0.remotePort'));
        $this->assertSame(20, $response->json('data.0.intervalMinutes'));
        $this->assertTrue($response->json('data.0.active'));
        $this->assertTrue($response->json('data.0.remotePasswordSet'));
        $this->assertSame('succeeded', $response->json('data.1.runStatus.lastResult'));
        $this->assertSame('EX_OK', $response->json('data.1.runStatus.exitStatus'));
        $this->assertTrue($response->json('data.2.runStatus.running'));
        $this->assertStringNotContainsString('remote-secret', (string) $response->getContent());
        $this->assertStringNotContainsString('other-secret', (string) $response->getContent());
        $this->assertStringNotContainsString('password1', (string) $response->getContent());
    }

    /**
     * Ten mailboxes in five domains with four jobs each, created in an interleaved order.
     *
     * @return list<int>
     */
    private function fortyJobs(): array
    {
        $ids = [];

        for ($round = 0; $round < 4; $round++) {
            for ($m = 0; $m < 10; $m++) {
                $mailbox = 'user'.$m.'@d'.($m % 5).'.example';
                $this->syncMailbox($mailbox);
                $ids[] = $this->mailcow()->syncJob($mailbox, ['user1' => 'remote'.$round]);
            }
        }

        return $ids;
    }

    public function test_an_administrator_gets_the_first_25_of_all_jobs_by_identifier_with_the_total(): void
    {
        $ids = $this->fortyJobs();
        $this->asAdmin();

        $response = $this->getJson('/api/v2/sync-jobs')->assertOk();

        $this->assertSame(array_slice($ids, 0, 25), $this->idsOf($response));
        $this->assertSame(['total' => 40, 'limit' => 25, 'offset' => 0], $response->json('meta'));
        $this->assertSame(array_slice($ids, 30, 5), $this->idsOf($this->getJson('/api/v2/sync-jobs?limit=5&offset=30')));
        $this->assertSame([], $this->idsOf($this->getJson('/api/v2/sync-jobs?offset=40')));
    }

    public function test_a_domain_administrator_and_their_read_only_key_see_only_their_domains(): void
    {
        $this->fortyJobs();
        $this->mailcow()->domainAdmin('reseller', ['d1.example', 'd3.example']);
        $expected = DB::table('imapsync')->where('user2', 'like', '%@d1.example')->orWhere('user2', 'like', '%@d3.example')->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $this->assertCount(16, $expected);

        $this->signInAs('reseller', Role::DomainAdmin);
        $this->assertSame($expected, $this->idsOf($this->getJson('/api/v2/sync-jobs?limit=100')));
        $this->forgetCookies();

        $key = $this->keyHeader($this->apiKey('reseller', 'readOnly'));
        $response = $this->getJson('/api/v2/sync-jobs?limit=100', $key)->assertOk();
        $this->assertSame($expected, $this->idsOf($response));
        $this->assertSame(16, $response->json('meta.total'));
    }

    public function test_an_invalid_limit_sort_or_filter_is_refused(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $this->getJson('/api/v2/sync-jobs?limit=1001')->assertUnprocessable()->assertJsonPath('errors.limit.0.code', 'outOfRange');
        $this->getJson('/api/v2/sync-jobs?sort=password')->assertUnprocessable()->assertJsonPath('errors.sort.0.code', 'notInList');
        $this->getJson('/api/v2/sync-jobs?lastResult=maybe')->assertUnprocessable()->assertJsonPath('errors.lastResult.0.code', 'notInList');
        $this->getJson('/api/v2/sync-jobs?active=1')->assertUnprocessable()->assertJsonPath('errors.active.0.code', 'invalidType');
        $this->getJson('/api/v2/sync-jobs?mailbox='.str_repeat('a', 256))->assertUnprocessable()->assertJsonPath('errors.mailbox.0.code', 'tooLong');
    }

    /**
     * Four jobs whose values put them in a known order for every sort field (see sorts()).
     *
     * @return array<string, int>
     */
    private function sortableJobs(): array
    {
        foreach (['alice@example.org', 'bob@example.org', 'carol@example.net'] as $mailbox) {
            $this->syncMailbox($mailbox);
        }

        $f = $this->mailcow();

        return [
            'A' => $f->syncJob('bob@example.org', ['host1' => 'b.example', 'user1' => 'u2', 'mins_interval' => 30, 'active' => 1, 'last_run' => '2026-01-02 00:00:00', 'success' => 1, 'created' => '2026-01-03 00:00:00', 'modified' => '2026-02-01 00:00:00']),
            'B' => $f->syncJob('alice@example.org', ['host1' => 'c.example', 'user1' => 'u1', 'mins_interval' => 10, 'active' => 0, 'last_run' => null, 'success' => null, 'created' => '2026-01-01 00:00:00', 'modified' => null]),
            'C' => $f->syncJob('carol@example.net', ['host1' => 'a.example', 'user1' => 'u3', 'mins_interval' => 30, 'active' => 1, 'last_run' => '2026-01-01 00:00:00', 'success' => 0, 'created' => '2026-01-02 00:00:00', 'modified' => '2026-03-01 00:00:00']),
            'D' => $f->syncJob('alice@example.org', ['host1' => 'a.example', 'user1' => 'u2', 'mins_interval' => 20, 'active' => 0, 'last_run' => '2026-01-03 00:00:00', 'success' => 0, 'created' => '2026-01-03 00:00:00', 'modified' => null]),
        ];
    }

    /**
     * Ascending orders; ties are broken by `id` in the same direction, so a descending order is
     * the exact reverse.
     *
     * @return array<string, array{string, string}>
     */
    public static function sorts(): array
    {
        return [
            'id' => ['id', 'ABCD'],
            'mailbox' => ['mailbox', 'BDAC'],
            'remoteHost' => ['remoteHost', 'CDAB'],
            'remoteUser' => ['remoteUser', 'BADC'],
            'intervalMinutes' => ['intervalMinutes', 'BDAC'],
            'active' => ['active', 'BDAC'],
            'lastRunAt (never run first)' => ['lastRunAt', 'BCAD'],
            'lastResult (unknown, failed, succeeded)' => ['lastResult', 'BCDA'],
            'createdAt' => ['createdAt', 'BCAD'],
            'modifiedAt' => ['modifiedAt', 'BDAC'],
        ];
    }

    #[DataProvider('sorts')]
    public function test_every_sort_field_orders_both_ways_with_ties_broken_by_identifier(string $sort, string $ascending): void
    {
        $jobs = $this->sortableJobs();
        $this->asAdmin();
        $expected = array_map(fn (string $letter): int => $jobs[$letter], str_split($ascending));

        $this->assertSame($expected, $this->idsOf($this->getJson('/api/v2/sync-jobs?sort='.$sort)));
        $this->assertSame($expected, $this->idsOf($this->getJson('/api/v2/sync-jobs?sort='.$sort.'&order=asc')));
        $this->assertSame(array_reverse($expected), $this->idsOf($this->getJson('/api/v2/sync-jobs?sort='.$sort.'&order=desc')));
    }

    public function test_the_filters_narrow_the_list_within_the_scope(): void
    {
        $jobs = $this->sortableJobs();
        $this->syncMailbox('erin@example.com');
        $running = $this->mailcow()->syncJob('erin@example.com', ['is_running' => 1]);
        $this->mailcow()->domainAdmin('reseller', ['example.org']);
        $this->asAdmin();

        $ids = fn (string $query): array => $this->idsOf($this->getJson('/api/v2/sync-jobs?'.$query)->assertOk());

        $this->assertSame([$jobs['B'], $jobs['D']], $ids('mailbox=ALICE@example.org'));
        $this->assertSame([$jobs['C']], $ids('domain=EXAMPLE.net'));
        $this->assertSame([$jobs['A'], $jobs['C'], $running], $ids('active=true'));
        $this->assertSame([$jobs['B'], $jobs['D']], $ids('active=false'));
        $this->assertSame([$running], $ids('running=true'));
        $this->assertSame([$jobs['A'], $jobs['B'], $jobs['C'], $jobs['D']], $ids('running=false'));
        $this->assertSame([$jobs['A']], $ids('lastResult=succeeded'));
        $this->assertSame([$jobs['C'], $jobs['D']], $ids('lastResult=failed'));
        $this->assertSame([$jobs['B'], $running], $ids('lastResult=unknown'));
        $this->assertSame([$jobs['D']], $ids('mailbox=alice@example.org&lastResult=failed&active=false'));

        $this->signInAs('reseller', Role::DomainAdmin);
        $this->assertSame([], $ids('mailbox=carol@example.net'));
        $this->assertSame([], $ids('domain=example.net'));
        $this->assertSame(0, $this->getJson('/api/v2/sync-jobs?mailbox=carol@example.net')->json('meta.total'));
    }

    public function test_the_search_matches_mailbox_remote_user_and_remote_host_as_escaped_substrings(): void
    {
        $this->syncMailbox('alice@example.org');
        $this->syncMailbox('mixed.Case@example.net');
        $f = $this->mailcow();
        $byHost = $f->syncJob('alice@example.org', ['host1' => 'IMAP.Provider.example', 'user1' => 'plain']);
        $percent = $f->syncJob('alice@example.org', ['host1' => 'h1.example', 'user1' => 'per%cent']);
        $underscore = $f->syncJob('alice@example.org', ['host1' => 'h2.example', 'user1' => 'under_score']);
        $byMailbox = $f->syncJob('mixed.Case@example.net', ['host1' => 'h3.example', 'user1' => 'x']);
        $this->asAdmin();

        $search = fn (string $q): array => $this->idsOf($this->getJson('/api/v2/sync-jobs?q='.rawurlencode($q))->assertOk());

        $this->assertSame([$byHost], $search('provider'));
        $this->assertSame([$byMailbox], $search('MIXED.case'));
        $this->assertSame([$percent], $search('%'));
        $this->assertSame([$underscore], $search('_'));
        $this->assertSame([$percent], $search('R%C'));
        $this->assertSame([$byHost, $percent, $underscore], $search('alice@'));
        $this->assertSame([], $search('nothing-matches'));
    }

    public function test_every_entry_carries_the_single_read_s_etag_and_its_log_availability(): void
    {
        $this->syncMailbox('alice@example.org');
        $f = $this->mailcow();
        $withLog = $f->syncJob('alice@example.org', ['user1' => 'a', 'returned_text' => 'Exiting with return value 0']);
        $emptyLog = $f->syncJob('alice@example.org', ['user1' => 'b', 'returned_text' => '']);
        $noLog = $f->syncJob('alice@example.org', ['user1' => 'c', 'returned_text' => null]);
        $this->asAdmin();

        $response = $this->getJson('/api/v2/sync-jobs')->assertOk();

        $this->assertSame([true, false, false], array_column(array_column((array) $response->json('data'), 'runStatus'), 'logAvailable'));

        foreach ([$withLog, $emptyLog, $noLog] as $i => $id) {
            $this->assertSame($this->getJson('/api/v2/sync-jobs/'.$id)->headers->get('ETag'), $response->json("data.{$i}.etag"));
        }

        $this->assertStringNotContainsString('Exiting with return value', (string) $response->getContent());
        $this->assertStringNotContainsString('remote-secret', (string) $response->getContent());
        $this->assertStringNotContainsString('returned_text', (string) $response->getContent());
    }
}
