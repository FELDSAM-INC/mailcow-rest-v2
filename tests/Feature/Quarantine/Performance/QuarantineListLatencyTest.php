<?php

namespace Tests\Feature\Quarantine\Performance;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Quarantine\QuarantineTestHelpers;
use Tests\TestCase;

/**
 * Feature 006 T081 (SC-007, research R16): a page of 25 quarantine items answers within 1 s at p95
 * on 100,000 items (a stored message of 2 KiB each, 50 mailboxes in 5 domains): the default page,
 * a domain administrator's page, a search and a page sorted by score. No index can be added to the
 * upstream table; the list never selects the message column.
 *
 * As in the other latency tests, the rows are committed and removed again in tearDown(): inside an
 * open transaction InnoDB checks the visibility of every uncommitted row, which makes full counts
 * several times slower than on a real server's committed data. Run with `composer
 * test:performance` on an otherwise idle machine.
 */
#[Group('performance')]
class QuarantineListLatencyTest extends TestCase
{
    use QuarantineTestHelpers;

    private const ITEMS = 100000;

    private const DOMAINS = 5;

    private const MAILBOXES = 50;

    /** @var list<string> committed rows instead of one transaction per test (see the class comment) */
    protected array $connectionsToTransact = [];

    private ?string $startedAt = null;

    protected function tearDown(): void
    {
        if ($this->startedAt !== null) {
            DB::table('quarantine')->where('rcpt', 'like', '%@q%.perf.example')->delete();
            DB::table('mailbox')->where('domain', 'like', 'q%.perf.example')->delete();
            DB::table('user_acl')->where('username', 'like', '%@q%.perf.example')->delete();
            DB::table('domain_admins')->where('username', 'perf-reseller')->delete();
            DB::table('domain')->where('domain', 'like', 'q%.perf.example')->delete();
            DB::table('admin')->whereIn('username', ['perf-admin', 'perf-reseller'])->delete();
            DB::table('da_acl')->where('username', 'perf-reseller')->delete();
            DB::table('restv2_audit_events')->where('occurred_at', '>=', $this->startedAt)->delete();
        }

        parent::tearDown();
    }

    private function seedQuarantine(): void
    {
        for ($d = 0; $d < self::DOMAINS; $d++) {
            $this->mailcow()->domain("q{$d}.perf.example");
        }

        for ($m = 0; $m < self::MAILBOXES; $m++) {
            $this->quarantineMailbox(sprintf('user%02d@q%d.perf.example', $m, $m % self::DOMAINS));
        }

        $message = "Subject: Offer\r\n\r\n".str_repeat("Cheap watches and more offers.\r\n", 64);
        $message = substr($message, 0, 2048);
        $actions = ['reject', 'add header', 'rewrite subject'];
        $rows = [];

        for ($i = 0; $i < self::ITEMS; $i++) {
            $m = $i % self::MAILBOXES;
            $rows[] = [
                'qid' => sprintf('PERF%08X', $i),
                'subject' => $i % 101 === 0 ? 'Invoice overdue '.$i : 'Offer '.$i,
                'score' => ($i % 400) / 10,
                'sender' => "sender{$i}@spam.example",
                'rcpt' => sprintf('user%02d@q%d.perf.example', $m, $m % self::DOMAINS),
                'symbols' => '[]',
                'fuzzy_hashes' => '[]',
                'user' => 'unknown',
                'ip' => '192.0.2.'.($i % 250),
                'msg' => $message,
                'action' => $actions[$i % 3],
                'notified' => $i % 2,
                'created' => date('Y-m-d H:i:s', 1760000000 + $i * 30),
            ];

            if (count($rows) === 1000) {
                DB::table('quarantine')->insert($rows);
                $rows = [];
            }
        }

        DB::update("UPDATE `quarantine` SET `qhash` = SHA2(CONCAT(`id`, `qid`), 256) WHERE `rcpt` LIKE '%@q%.perf.example'");
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

    /**
     * @return list<float>
     */
    private function twenty(string $query): array
    {
        $durations = [];

        for ($i = 0; $i < 20; $i++) {
            $offset = ($i * 25) % 500;
            $durations[] = $this->timed(fn () => $this->getJson("/api/v2/quarantine?limit=25&offset={$offset}{$query}")->assertOk());
        }

        return $durations;
    }

    public function test_quarantine_pages_answer_within_one_second_at_p95(): void
    {
        $this->assertSame(0, DB::table('quarantine')->where('rcpt', 'like', '%@q%.perf.example')->count(), 'Leftovers of an earlier run.');
        $this->startedAt = now('UTC')->subSecond()->format('Y-m-d H:i:s');
        $this->seedQuarantine();
        $this->mailcow()->admin('perf-admin', 'Passw0rd!', 'SSHA256');
        $this->mailcow()->domainAdmin('perf-reseller', ['q1.perf.example', 'q2.perf.example'], scheme: 'SSHA256');

        $this->signInAs('perf-admin', Role::Admin);
        $this->assertGreaterThanOrEqual(self::ITEMS, $this->getJson('/api/v2/quarantine?limit=1')->assertOk()->json('meta.total'));

        foreach (['' => 'default page', '&q=invoice' => 'search', '&q=spam.example' => 'search matching every item', '&sort=score&order=desc' => 'sorted by score'] as $query => $label) {
            $p95 = self::p95($this->twenty($query));
            if (getenv('RESTV2_PERF_DEBUG')) {
                fwrite(STDERR, sprintf("%s: p95 %.3f s\n", $label, $p95));
            }
            $this->assertLessThan(1.0, $p95, sprintf('listQuarantineItems (%s): p95 %.3f s', $label, $p95));
        }

        $this->forgetCookies();
        $this->signInAs('perf-reseller', Role::DomainAdmin);
        $this->getJson('/api/v2/quarantine?limit=1')->assertOk()->assertJsonPath('meta.total', self::ITEMS * 2 / self::DOMAINS);
        $p95 = self::p95($this->twenty(''));
        if (getenv('RESTV2_PERF_DEBUG')) {
            fwrite(STDERR, sprintf("domain administrator: p95 %.3f s\n", $p95));
        }
        $this->assertLessThan(1.0, $p95, sprintf('listQuarantineItems as a domain administrator: p95 %.3f s', $p95));
    }
}
