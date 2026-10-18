<?php

namespace Tests\Feature\Performance;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\SyncJobs\SyncJobTestHelpers;
use Tests\TestCase;

/**
 * Feature 008 T065 (SC-006, research R19): a page of 25 sync jobs with run status answers within
 * 1 s at p95 on a server with 100,000 mailboxes over 200 domains and 5,000 jobs, 500 of them with a
 * large stored log: sorted by the last run as an administrator, as a domain administrator of two
 * domains, and with a search and a last-result filter.
 *
 * The logs are 256 KiB each (128 MiB in all) rather than 1 MiB: InnoDB keeps every value of this
 * size off the row page, so a larger value changes nothing for a query that never reads the column,
 * and it spares the shared database half a gigabyte of writes. As in 004's performance test, the
 * rows are committed (counts inside an open transaction check the visibility of every uncommitted
 * row) and removed again in tearDown().
 */
#[Group('performance')]
class SyncJobListLatencyTest extends TestCase
{
    use SyncJobTestHelpers;

    private const DOMAINS = 200;

    private const MAILBOXES = 100000;

    private const JOBS = 5000;

    /** @var list<string> committed rows instead of one transaction per test (see the class comment) */
    protected array $connectionsToTransact = [];

    private ?string $startedAt = null;

    protected function tearDown(): void
    {
        if ($this->startedAt !== null) {
            DB::table('imapsync')->where('user2', 'like', '%@perf008-%')->delete();
            DB::table('mailbox')->where('domain', 'like', 'perf008-%')->delete();
            DB::table('domain_admins')->where('domain', 'like', 'perf008-%')->delete();
            DB::table('domain')->where('domain', 'like', 'perf008-%')->delete();
            DB::table('admin')->whereIn('username', ['admin', 'reseller'])->delete();
            DB::table('da_acl')->where('username', 'reseller')->delete();
            DB::table('restv2_audit_events')->where('occurred_at', '>=', $this->startedAt)->delete();
        }

        parent::tearDown();
    }

    private function seedServer(): void
    {
        DB::statement("INSERT INTO domain (domain, description, aliases, mailboxes, defquota, maxquota, quota, backupmx, gal, active, relay_unknown_only, relay_all_recipients)
            SELECT CONCAT('perf008-', LPAD(seq, 3, '0'), '.example'), '', 400, 1000, 1024, 10240, 1024000, 0, 1, 1, 0, 0 FROM seq_1_to_".self::DOMAINS);
        DB::statement("INSERT INTO mailbox (username, password, name, quota, local_part, domain, kind, active)
            SELECT CONCAT('u', seq, '@perf008-', LPAD(seq % ".self::DOMAINS." + 1, 3, '0'), '.example'), '{PLAIN}x', CONCAT('u', seq), 0, CONCAT('u', seq),
                CONCAT('perf008-', LPAD(seq % ".self::DOMAINS." + 1, 3, '0'), '.example'), '', 1 FROM seq_1_to_".self::MAILBOXES);
        DB::statement("INSERT INTO imapsync (user2, exclude, delete1, delete2, timeout1, timeout2, automap, skipcrossduplicates, maxbytespersecond, subscribeall, dry, maxage, subfolder2, host1, authmech1, user1, password1, mins_interval, port1, enc1, delete2duplicates, custom_params, active, last_run, success, exit_status)
            SELECT CONCAT('u', seq * 20, '@perf008-', LPAD(seq * 20 % ".self::DOMAINS." + 1, 3, '0'), '.example'), '(?i)spam|(?i)junk', 0, 0, 600, 600, 1, 0, '0', 1, 0, 0, '',
                CONCAT('imap', seq % 50, '.provider.example'), 'PLAIN', CONCAT('remote', seq), 'remote-secret', 20, 993, 'SSL', 1, '', seq % 2,
                IF(seq % 7 = 0, NULL, NOW() - INTERVAL seq MINUTE), IF(seq % 7 = 0, NULL, seq % 3 = 0), IF(seq % 7 = 0, NULL, IF(seq % 3 = 0, 'EX_OK', 'EXIT_CONNECTION_FAILURE'))
            FROM seq_1_to_".self::JOBS);
        DB::statement("UPDATE imapsync SET returned_text = REPEAT('msg copied to INBOX/Archive ', 9363) WHERE user2 LIKE '%@perf008-%' AND id % 10 = 0");
    }

    /**
     * @param  list<float>  $seconds
     */
    private static function p95(array $seconds): float
    {
        sort($seconds);

        return $seconds[(int) ceil(0.95 * count($seconds)) - 1];
    }

    public function test_a_page_of_25_jobs_answers_within_one_second_at_p95(): void
    {
        $this->assertSame(0, DB::table('domain')->where('domain', 'like', 'perf008-%')->count(), 'Leftovers of an earlier run.');
        $this->startedAt = now('UTC')->subSecond()->format('Y-m-d H:i:s');
        $this->seedServer();
        $this->assertSame(500, DB::table('imapsync')->where('user2', 'like', '%@perf008-%')->whereNotNull('returned_text')->count());
        $this->mailcow()->domainAdmin('reseller', ['perf008-001.example', 'perf008-002.example'], ['syncjobs' => 1], scheme: 'SSHA256');

        $cases = [
            'admin sorted by last run' => ['admin', '?sort=lastRunAt&order=desc'],
            'domain administrator of two domains' => ['reseller', '?sort=lastRunAt'],
            'search and last result' => ['admin', '?q=provider&lastResult=failed&sort=remoteHost'],
        ];

        foreach ($cases as $case => [$caller, $query]) {
            $caller === 'admin' ? $this->asAdmin() : $this->signInAs('reseller', Role::DomainAdmin);
            $durations = [];

            for ($i = 0; $i < 20; $i++) {
                $offset = ($i * 25) % 200;
                $start = hrtime(true);
                $response = $this->getJson("/api/v2/sync-jobs{$query}&limit=25&offset={$offset}")->assertOk();
                $durations[] = (hrtime(true) - $start) / 1e9;
            }

            $this->assertGreaterThan(0, $response->json('meta.total'), $case);
            $this->assertLessThan(1.0, self::p95($durations), sprintf('%s: p95 %.3f s', $case, self::p95($durations)));
        }
    }
}
