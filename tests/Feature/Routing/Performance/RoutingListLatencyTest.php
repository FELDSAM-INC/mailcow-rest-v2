<?php

namespace Tests\Feature\Routing\Performance;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T101 (SC-006, research R23): a page of 25 entries answers within 1 s at p95 for
 * 10,000 relayhosts (with 10,000 mailboxes and 1,000 domains assigned across them), transports, BCC
 * maps, recipient maps, TLS policy maps and forwarding hosts, with the default sort, a search and
 * a sort by a derived or other column.
 *
 * As in feature 004's latency test, the rows are committed and removed again in tearDown(): inside
 * an open transaction InnoDB checks the visibility of every uncommitted row, which makes counts
 * slower than on a real server's committed data.
 */
#[Group('performance')]
class RoutingListLatencyTest extends TestCase
{
    use RoutingTestHelpers;

    private const ROWS = 10000;

    /** @var list<string> committed rows instead of one transaction per test (see the class comment) */
    protected array $connectionsToTransact = [];

    private ?string $startedAt = null;

    protected function tearDown(): void
    {
        if ($this->startedAt !== null) {
            DB::table('relayhosts')->where('hostname', 'like', 'perf%')->delete();
            DB::table('transports')->where('destination', 'like', 'perf%')->delete();
            DB::table('bcc_maps')->where('domain', 'like', 'perf%')->delete();
            DB::table('recipient_maps')->where('old_dest', 'like', '@perf%')->delete();
            DB::table('tls_policy_override')->where('dest', 'like', 'perf%')->delete();
            DB::table('mailbox')->where('domain', 'like', 'perf%')->delete();
            DB::table('domain')->where('domain', 'like', 'perf%')->delete();
            DB::table('admin')->where('username', 'admin')->delete();
            DB::table('restv2_audit_events')->where('occurred_at', '>=', $this->startedAt)->delete();
        }

        parent::tearDown();
    }

    private function seedRouting(): void
    {
        $this->startedAt = now('UTC')->subSecond()->format('Y-m-d H:i:s');
        $relayhosts = $transports = $bcc = $recipient = $tls = $domains = $mailboxes = [];

        for ($i = 1; $i <= self::ROWS; $i++) {
            $relayhosts[] = ['hostname' => "perf{$i}.relay.example", 'username' => $i % 3 === 0 ? "user{$i}" : '', 'password' => $i % 2 === 0 ? 'pw' : '', 'active' => $i % 5 === 0 ? 0 : 1];
            $transports[] = ['destination' => "perf{$i}.example", 'nexthop' => '[hop'.($i % 500).'.example]:25', 'username' => '', 'password' => '', 'is_mx_based' => 0, 'active' => 1];
            $bcc[] = ['local_dest' => "user{$i}@perf".($i % 100).'.example', 'bcc_dest' => 'archive@backup.example', 'domain' => 'perf'.($i % 100).'.example', 'type' => $i % 2 === 0 ? 'sender' : 'rcpt', 'active' => 1];
            $recipient[] = ['old_dest' => "@perf{$i}.example", 'new_dest' => "@new{$i}.example", 'active' => 1];
            $tls[] = ['dest' => "perf{$i}.example", 'policy' => 'encrypt', 'parameters' => '', 'active' => 1];

            if (count($relayhosts) === 2000) {
                DB::table('relayhosts')->insert($relayhosts);
                DB::table('transports')->insert($transports);
                DB::table('bcc_maps')->insert($bcc);
                DB::table('recipient_maps')->insert($recipient);
                DB::table('tls_policy_override')->insert($tls);
                $relayhosts = $transports = $bcc = $recipient = $tls = [];
            }
        }

        $first = (int) DB::table('relayhosts')->where('hostname', 'perf1.relay.example')->value('id');

        for ($d = 0; $d < 1000; $d++) {
            $domains[] = ['domain' => "perf{$d}.example", 'description' => '', 'aliases' => 400, 'mailboxes' => 100, 'defquota' => 1024, 'maxquota' => 10240, 'quota' => 102400, 'relayhost' => (string) ($first + $d % 300), 'backupmx' => 0, 'gal' => 1, 'active' => 1, 'relay_unknown_only' => 0, 'relay_all_recipients' => 0];
        }

        DB::table('domain')->insert($domains);

        for ($m = 0; $m < self::ROWS; $m++) {
            $domain = 'perf'.($m % 1000).'.example';
            $mailboxes[] = ['username' => "u{$m}@{$domain}", 'password' => '{PLAIN}x', 'name' => "u{$m}", 'quota' => 0, 'local_part' => "u{$m}", 'domain' => $domain, 'kind' => '', 'active' => 1, 'attributes' => json_encode(['relayhost' => (string) ($first + $m % 700)])];

            if (count($mailboxes) === 2000) {
                DB::table('mailbox')->insert($mailboxes);
                $mailboxes = [];
            }
        }

        $redis = $this->redis();

        foreach (array_chunk(range(0, self::ROWS - 1), 1000) as $chunk) {
            $fields = [];

            foreach ($chunk as $i) {
                $fields[sprintf('10.%d.%d.0/24', intdiv($i, 256), $i % 256)] = 'perf'.($i % 50).'.example';
            }

            $redis->hMSet('WHITELISTED_FWD_HOST', $fields);
        }
    }

    public function test_pages_stay_within_one_second_at_the_95th_percentile(): void
    {
        $this->seedRouting();
        $this->asAdmin();

        foreach ([
            '/api/v2/relayhosts',
            '/api/v2/relayhosts?q=perf77',
            '/api/v2/relayhosts?sort=domainCount&order=desc',
            '/api/v2/relayhosts?sort=mailboxCount&order=desc',
            '/api/v2/relayhost-choices?q=perf9',
            '/api/v2/transports',
            '/api/v2/transports?q=perf55',
            '/api/v2/transports?sort=nextHop',
            '/api/v2/bcc-maps',
            '/api/v2/bcc-maps?q=user99',
            '/api/v2/bcc-maps?sort=domain&order=desc',
            '/api/v2/recipient-maps',
            '/api/v2/recipient-maps?q=new42',
            '/api/v2/recipient-maps?sort=newDestination',
            '/api/v2/tls-policy-maps',
            '/api/v2/tls-policy-maps?q=perf3',
            '/api/v2/tls-policy-maps?sort=destination&order=desc',
            '/api/v2/forwarding-hosts',
            '/api/v2/forwarding-hosts?q=10.3',
            '/api/v2/forwarding-hosts?sort=source',
        ] as $uri) {
            $durations = [];

            for ($i = 0; $i < 20; $i++) {
                $started = microtime(true);
                $this->getJson($uri)->assertOk();
                $durations[] = microtime(true) - $started;
            }

            sort($durations);
            $p95 = $durations[(int) ceil(0.95 * count($durations)) - 1];
            $this->assertLessThan(1.0, $p95, sprintf('%s: p95 %.3f s', $uri, $p95));
        }

        $this->assertSame(self::ROWS, $this->getJson('/api/v2/forwarding-hosts?limit=1')->json('meta.total'));
        $this->assertGreaterThan(0, $this->getJson('/api/v2/relayhosts?sort=mailboxCount&order=desc&limit=1')->json('data.0.usage.mailboxes'));
    }
}
