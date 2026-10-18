<?php

namespace Tests\Feature\Logs;

use Illuminate\Support\Facades\Redis;
use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\Support\Redis\MailcowLogs;
use Tests\Support\Redis\UnavailableSharedStore;
use Tests\TestCase;

/**
 * Feature 011 T047 (US3 AS-1, AS-2, AS-3, AS-6, FR-024 to FR-027, research R12): paging, sorting,
 * search, filters and duplicate-free cursors of the in-memory logs.
 */
class LogPagingAndFiltersTest extends TestCase
{
    use MonitoringTestHelpers;

    private const T = 1760781600;

    /**
     * Pushes $count Postfix entries, oldest first, one second apart; message `n<number>`.
     */
    private function fill(int $count, int $start = 0): void
    {
        $lines = [];

        for ($i = $start; $i < $start + $count; $i++) {
            $lines[] = MailcowLogs::syslogLine(self::T + $i, 'info', 'postfix/smtpd', "n{$i}");
        }

        MailcowLogs::rawMany('POSTFIX_MAILLOG', $lines);
    }

    public function test_the_newest_entries_come_first_with_the_total(): void
    {
        $this->fill(10000);
        $this->asAdmin();

        $response = $this->getJson('/api/v2/logs/postfix')->assertOk();

        $response->assertJsonPath('meta', ['total' => 10000, 'limit' => 25, 'offset' => 0]);
        $this->assertSame('n9999', $response->json('data.0.message'));
        $this->assertSame('n9975', $response->json('data.24.message'));
    }

    public function test_oldest_first(): void
    {
        $this->fill(30);
        $this->asAdmin();

        $this->assertSame(['n0', 'n1', 'n2'], array_column((array) $this->getJson('/api/v2/logs/postfix?order=asc&limit=3')->assertOk()->json('data'), 'message'));
    }

    public function test_entries_with_the_same_time_keep_the_stored_order(): void
    {
        foreach (['first', 'second', 'third'] as $message) {
            MailcowLogs::syslog('POSTFIX_MAILLOG', self::T, 'info', 'postfix/smtpd', $message);
        }
        $this->asAdmin();

        $this->assertSame(['third', 'second', 'first'], array_column((array) $this->getJson('/api/v2/logs/postfix')->json('data'), 'message'));
        $this->assertSame(['first', 'second', 'third'], array_column((array) $this->getJson('/api/v2/logs/postfix?order=asc')->json('data'), 'message'));
    }

    public function test_the_search_is_case_insensitive(): void
    {
        MailcowLogs::syslog('POSTFIX_MAILLOG', self::T, 'info', 'postfix/smtp', 'to=<alice@example.org>, status=bounced');
        MailcowLogs::syslog('POSTFIX_MAILLOG', self::T + 1, 'info', 'postfix/smtp', 'to=<bob@example.org>, status=sent');
        $this->asAdmin();

        $response = $this->getJson('/api/v2/logs/postfix?q=ALICE@example.org')->assertOk();

        $response->assertJsonPath('meta.total', 1);
        $this->assertStringContainsString('alice@example.org', (string) $response->json('data.0.message'));
    }

    public function test_the_error_severity_class(): void
    {
        foreach (['err', 'warning', 'info', 'emerg', 'alert', 'crit', 'notice', 'debug', 'warn'] as $i => $priority) {
            MailcowLogs::syslog('POSTFIX_MAILLOG', self::T + $i, $priority, 'postfix/smtpd', $priority);
        }
        $this->asAdmin();

        $this->assertSame(['crit', 'alert', 'emerg', 'err'], array_column((array) $this->getJson('/api/v2/logs/postfix?severity=error')->json('data'), 'priority'));
        $this->assertSame(['warn', 'warning'], array_column((array) $this->getJson('/api/v2/logs/postfix?severity=warning')->json('data'), 'priority'));
        $this->assertCount(7, (array) $this->getJson('/api/v2/logs/postfix?severity=error&severity=info')->json('data'));
    }

    public function test_from_and_to_are_inclusive(): void
    {
        $this->fill(10);
        $this->asAdmin();

        $from = gmdate('Y-m-d\TH:i:s\Z', self::T + 3);
        $to = gmdate('Y-m-d\TH:i:s\Z', self::T + 5);

        $this->assertSame(['n5', 'n4', 'n3'], array_column((array) $this->getJson("/api/v2/logs/postfix?from={$from}&to={$to}")->json('data'), 'message'));
    }

    public function test_paging_older_entries_while_new_ones_arrive_has_no_duplicates(): void
    {
        $this->fill(300);
        $this->asAdmin();

        $first = (array) $this->getJson('/api/v2/logs/postfix?limit=100')->json('data');
        $oldest = end($first)['id'];
        $this->fill(50, 300);

        $next = $this->getJson('/api/v2/logs/postfix?limit=100&before='.urlencode($oldest))->assertOk();

        $this->assertSame('n199', $next->json('data.0.message'));
        $this->assertSame('n100', $next->json('data.99.message'));
        $this->assertSame([], array_intersect(array_column($first, 'id'), array_column((array) $next->json('data'), 'id')));
        $next->assertJsonPath('meta.total', 200);
    }

    public function test_a_trimmed_cursor_returns_nothing_older(): void
    {
        $this->fill(100);
        $this->asAdmin();
        $oldest = $this->getJson('/api/v2/logs/postfix?order=asc&limit=1')->json('data.0.id');

        Redis::connection('mailcow')->command('ltrim', ['POSTFIX_MAILLOG', 0, 49]);

        $this->getJson('/api/v2/logs/postfix?before='.urlencode((string) $oldest))->assertOk()->assertJsonPath('data', []);
    }

    public function test_a_cursor_removed_from_the_middle_falls_back_to_strictly_older_times(): void
    {
        $this->fill(10);
        $this->asAdmin();
        $entries = (array) $this->getJson('/api/v2/logs/postfix')->json('data');
        $cursor = $entries[4]['id'];
        Redis::connection('mailcow')->command('lrem', ['POSTFIX_MAILLOG', MailcowLogs::syslogLine(self::T + 5, 'info', 'postfix/smtpd', 'n5'), 1]);

        $this->assertSame(['n4', 'n3', 'n2', 'n1', 'n0'], array_column((array) $this->getJson('/api/v2/logs/postfix?before='.urlencode((string) $cursor))->json('data'), 'message'));
    }

    public function test_identical_lines_get_distinct_cursors(): void
    {
        foreach ([1, 2] as $_) {
            MailcowLogs::syslog('POSTFIX_MAILLOG', self::T, 'info', 'postfix/smtpd', 'same');
        }
        $this->asAdmin();

        $entries = (array) $this->getJson('/api/v2/logs/postfix')->json('data');
        $this->assertNotSame($entries[0]['id'], $entries[1]['id']);
        $this->assertCount(1, (array) $this->getJson('/api/v2/logs/postfix?before='.urlencode($entries[0]['id']))->json('data'));
    }

    public function test_malformed_parameters_are_refused(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $this->getJson('/api/v2/logs/postfix?before=not-a-cursor')
            ->assertStatus(422)
            ->assertJsonPath('errors.before.0.code', 'invalidFormat')
            ->assertJsonPath('errors.before.0.params.format', 'logCursor');
        $this->getJson('/api/v2/logs/postfix?limit=1001')->assertStatus(422)->assertJsonPath('errors.limit.0.code', 'outOfRange');
        $this->getJson('/api/v2/logs/postfix?from=yesterday')->assertStatus(422)->assertJsonPath('errors.from.0.code', 'invalidFormat');
        $errors = (array) $this->getJson('/api/v2/logs/postfix?severity=fatal')->assertStatus(422)->json('errors');
        $this->assertSame('notInList', $errors['severity.0'][0]['code'] ?? null);
        $this->getJson('/api/v2/logs/postfix?sort=priority')->assertStatus(422)->assertJsonPath('errors.sort.0.code', 'notInList');
    }

    public function test_an_unavailable_shared_store_is_a_temporary_failure(): void
    {
        UnavailableSharedStore::bind($this->app);
        $this->asAdmin();

        $this->getJson('/api/v2/logs/postfix')->assertStatus(503)->assertJsonPath('type', '/api/v2/problems/temporary-failure');
    }
}
