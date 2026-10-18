<?php

namespace Tests\Feature\Performance;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\TestCase;

/**
 * Feature 003 T088 (SC-002, SC-009): on 10,000 mailboxes in 500 domains with usage, tags and logins,
 * 95% of pages of 100 with any filter, search and sort answer within 1 s, a page of 1,000 within
 * 5 s; a bulk deactivation of 100 mailboxes finishes within 10 s and a bulk deletion of 100 (with
 * the container service fake answering at once) within 60 s.
 *
 * As in feature 002's latency test, the bulk timings run with the groupware disabled: they measure
 * this API's work, not legacy's groupware directory statements (the per-request cache flush is
 * asserted in MailboxBulkActionTest).
 */
#[Group('performance')]
class MailboxListPerformanceTest extends TestCase
{
    use MailboxTestHelpers;

    private const DOMAINS = 500;

    private const MAILBOXES = 10000;

    private function seedServer(): void
    {
        $domains = [];

        for ($d = 0; $d < self::DOMAINS; $d++) {
            $domains[] = [
                'domain' => sprintf('d%03d.example', $d), 'description' => '', 'aliases' => 400, 'mailboxes' => 100,
                'defquota' => 1024, 'maxquota' => 10240, 'quota' => 102400, 'backupmx' => 0, 'gal' => 1, 'active' => 1,
                'relay_unknown_only' => 0, 'relay_all_recipients' => 0,
            ];
        }

        DB::table('domain')->insert($domains);
        $attributes = json_encode([
            'force_pw_update' => '0', 'force_tfa' => '0', 'tls_enforce_in' => '0', 'tls_enforce_out' => '0', 'sogo_access' => '1',
            'imap_access' => '1', 'pop3_access' => '1', 'smtp_access' => '1', 'sieve_access' => '1', 'eas_access' => '1', 'dav_access' => '1',
            'relayhost' => '0', 'passwd_update' => '2026-01-01 10:00:00', 'mailbox_format' => 'maildir:', 'quarantine_notification' => 'hourly',
            'quarantine_category' => 'reject', 'attribute_hash' => '',
        ]);
        $rows = ['mailbox' => [], 'quota2' => [], 'user_acl' => [], 'alias' => [], 'tags_mailbox' => [], 'sasl_log' => []];

        for ($m = 0; $m < self::MAILBOXES; $m++) {
            $domain = sprintf('d%03d.example', $m % self::DOMAINS);
            $username = "user{$m}@{$domain}";
            $rows['mailbox'][] = ['username' => $username, 'password' => '{PLAIN}x', 'name' => "User {$m}", 'quota' => ($m % 3) * 1073741824, 'local_part' => "user{$m}", 'domain' => $domain, 'attributes' => $attributes, 'kind' => '', 'active' => $m % 7 === 0 ? 2 : 1];
            $rows['quota2'][] = ['username' => $username, 'bytes' => $m * 1000, 'messages' => $m % 100];
            $rows['user_acl'][] = ['username' => $username];
            $rows['alias'][] = ['address' => $username, 'goto' => $username, 'domain' => $domain, 'active' => 1];
            $rows['tags_mailbox'][] = ['username' => $username, 'tag_name' => 'tag'.($m % 20)];
            $rows['sasl_log'][] = ['username' => $username, 'service' => ['imap', 'smtp', 'pop3', 'SSO'][$m % 4], 'real_rip' => '192.0.2.1', 'datetime' => sprintf('2026-10-%02d 10:00:00', $m % 28 + 1)];

            if (count($rows['mailbox']) === 1000) {
                foreach ($rows as $table => $chunk) {
                    DB::table($table)->insert($chunk);
                }

                $rows = array_map(fn (): array => [], $rows);
            }
        }
    }

    /**
     * @param  list<float>  $seconds
     */
    private static function p95(array $seconds): float
    {
        sort($seconds);

        return $seconds[(int) ceil(0.95 * count($seconds)) - 1];
    }

    private function timed(callable $call): float
    {
        $start = hrtime(true);
        $call();

        return (hrtime(true) - $start) / 1e9;
    }

    public function test_pages_and_bulk_actions_stay_within_their_budgets(): void
    {
        $this->seedServer();
        $this->asAdmin();

        $queries = ['', '&domain=d001.example&domain=d002.example', '&status=loginDisabled', '&tag=tag3', '&authSource=mailcow', '&q=user12'];

        foreach (['address', 'displayName', 'domain', 'status', 'quota', 'quotaUsed', 'percentUsed', 'messageCount', 'lastMailLogin', 'passwordChangedAt', 'createdAt', 'modifiedAt'] as $sort) {
            $queries[] = "&sort={$sort}&order=desc";
        }

        $durations = [];

        foreach ($queries as $index => $query) {
            for ($run = 0; $run < 3; $run++) {
                $offset = (($index * 3 + $run) * 997) % 5000;
                $durations[] = $this->timed(fn () => $this->getJson("/api/v2/mailboxes?limit=100&offset={$offset}{$query}")->assertOk());
            }
        }

        if (getenv('RESTV2_PERF_DEBUG')) {
            foreach ($queries as $index => $query) {
                fwrite(STDERR, sprintf("%-40s %.3f %.3f %.3f\n", $query, $durations[$index * 3], $durations[$index * 3 + 1], $durations[$index * 3 + 2]));
            }
        }

        $this->assertLessThan(1.0, self::p95($durations), sprintf('pages of 100: p95 %.3f s', self::p95($durations)));

        $large = [];

        for ($run = 0; $run < 3; $run++) {
            $large[] = $this->timed(fn () => $this->getJson('/api/v2/mailboxes?limit=1000&offset='.($run * 1000).'&sort=quotaUsed')->assertOk()->assertJsonCount(1000, 'data'));
        }

        $this->assertLessThan(5.0, max($large), sprintf('pages of 1,000: max %.3f s', max($large)));

        config(['restv2.skip_sogo' => true]);
        $csrf = $this->csrf();
        $targets = array_map(fn (int $m): array => ['id' => sprintf('user%d@d%03d.example', $m, $m % self::DOMAINS)], range(1, 100));
        $deactivate = $this->timed(fn () => $this->postJson('/api/v2/mailboxes/bulk-actions', ['action' => 'deactivate', 'targets' => $targets], $csrf)->assertOk()->assertJsonPath('data.summary.total', 100));
        $this->assertLessThan(10.0, $deactivate, sprintf('bulk deactivation: %.3f s', $deactivate));

        $delete = $this->timed(fn () => $this->postJson('/api/v2/mailboxes/bulk-actions', ['action' => 'delete', 'targets' => $targets], $csrf)->assertOk()->assertJsonPath('data.summary.done', 100));
        $this->assertLessThan(60.0, $delete, sprintf('bulk deletion: %.3f s', $delete));
    }
}
