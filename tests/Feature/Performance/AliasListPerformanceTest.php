<?php

namespace Tests\Feature\Performance;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Aliases\AliasTestHelpers;
use Tests\TestCase;

/**
 * Feature 004 T084 (SC-005, SC-010): a page of 25 aliases answers within 1 s at p95 on 5,000
 * domains, 100,000 mailboxes (with their own-alias rows) and 200,000 aliases, with a search, a
 * destination filter and as a domain administrator too; a bulk deactivation of 100 aliases finishes
 * within 10 s.
 *
 * As in feature 002's latency test, the bulk timing runs with the groupware disabled: each alias
 * change refreshes its destinations' groupware rows with legacy's own statement, which joins the
 * grouped views without indexes and is slow on large servers in legacy as here; the refresh itself
 * is covered by AliasGroupwareRefreshTest.
 *
 * Unlike other tests, the rows are committed and removed again in tearDown(): inside an open
 * transaction InnoDB checks the visibility of every uncommitted row, which makes full counts about
 * four times slower than on a real server's committed data.
 */
#[Group('performance')]
class AliasListPerformanceTest extends TestCase
{
    use AliasTestHelpers;

    private const DOMAINS = 5000;

    private const MAILBOXES = 100000;

    private const ALIASES = 200000;

    /** @var list<string> committed rows instead of one transaction per test (see the class comment) */
    protected array $connectionsToTransact = [];

    private ?string $startedAt = null;

    protected function tearDown(): void
    {
        if ($this->startedAt !== null) {
            foreach (['alias', 'mailbox', 'domain_admins'] as $table) {
                DB::table($table)->where('domain', 'like', 'd0%.example')->delete();
            }

            DB::table('domain')->where('domain', 'like', 'd0%.example')->delete();
            DB::table('admin')->whereIn('username', ['admin', 'reseller'])->delete();
            DB::table('da_acl')->where('username', 'reseller')->delete();
            DB::table('restv2_audit_events')->where('occurred_at', '>=', $this->startedAt)->delete();
        }

        parent::tearDown();
    }

    private function seedServer(): void
    {
        $domains = [];
        for ($d = 0; $d < self::DOMAINS; $d++) {
            $domains[] = [
                'domain' => sprintf('d%05d.example', $d), 'description' => 'Customer '.$d, 'aliases' => 400, 'mailboxes' => 100,
                'defquota' => 1024, 'maxquota' => 10240, 'quota' => 102400, 'backupmx' => 0, 'gal' => 1, 'active' => 1,
                'relay_unknown_only' => 0, 'relay_all_recipients' => 0,
            ];
        }
        foreach (array_chunk($domains, 1000) as $chunk) {
            DB::table('domain')->insert($chunk);
        }

        $mailboxes = [];
        $own = [];
        for ($m = 0; $m < self::MAILBOXES; $m++) {
            $domain = sprintf('d%05d.example', $m % self::DOMAINS);
            $username = "u{$m}@{$domain}";
            $mailboxes[] = ['username' => $username, 'password' => '{PLAIN}x', 'name' => "u{$m}", 'quota' => 0, 'local_part' => "u{$m}", 'domain' => $domain, 'kind' => '', 'active' => 1];
            $own[] = ['address' => $username, 'goto' => $username, 'domain' => $domain, 'active' => 1, 'sogo_visible' => 1];

            if (count($mailboxes) === 2000) {
                DB::table('mailbox')->insert($mailboxes);
                DB::table('alias')->insert($own);
                $mailboxes = $own = [];
            }
        }

        $aliases = [];
        for ($a = 0; $a < self::ALIASES; $a++) {
            $domain = sprintf('d%05d.example', $a % self::DOMAINS);
            $aliases[] = ['address' => "a{$a}@{$domain}", 'goto' => 'u'.($a % 1000).'@'.sprintf('d%05d.example', $a % 1000 % self::DOMAINS), 'domain' => $domain, 'active' => 1, 'sogo_visible' => 1, 'public_comment' => $a % 97 === 0 ? 'invoice desk' : null];

            if (count($aliases) === 2000) {
                DB::table('alias')->insert($aliases);
                $aliases = [];
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

    public function test_list_pages_and_a_bulk_action_stay_within_their_budgets(): void
    {
        $this->assertSame(0, DB::table('domain')->where('domain', 'like', 'd0%.example')->count(), 'Leftovers of an earlier run.');
        $this->startedAt = now('UTC')->subSecond()->format('Y-m-d H:i:s');
        $this->seedServer();
        $this->mailcow()->domainAdmin('reseller', ['d00001.example', 'd00002.example'], scheme: 'SSHA256');
        $this->asAdmin();
        config(['restv2.skip_sogo' => true]);

        foreach (['' => 'admin', '&q=invoice' => 'admin', '&destination=u7@d00007.example' => 'admin'] as $query => $role) {
            $durations = [];

            for ($i = 0; $i < 20; $i++) {
                $offset = ($i * 13) % 50;
                $durations[] = $this->timed(fn () => $this->getJson("/api/v2/aliases?limit=25&offset={$offset}{$query}")->assertOk());
            }

            $this->assertLessThan(1.0, self::p95($durations), sprintf('listAliases%s: p95 %.3f s', $query, self::p95($durations)));
        }

        $this->assertSame(self::ALIASES, $this->getJson('/api/v2/aliases?limit=1')->json('meta.total'));

        $ids = DB::table('alias')->where('address', 'like', 'a%')->orderBy('id')->limit(100)->pluck('id')->map(fn (mixed $id): array => ['id' => (string) $id])->all();
        $csrf = $this->csrf();
        $deactivate = $this->timed(fn () => $this->postJson('/api/v2/aliases/bulk-actions', ['action' => 'deactivate', 'targets' => $ids], $csrf)->assertOk()->assertJsonPath('data.summary.done', 100));
        $this->assertLessThan(10.0, $deactivate, sprintf('bulk deactivation: %.3f s', $deactivate));

        $this->signInAs('reseller', Role::DomainAdmin);
        $durations = [];

        for ($i = 0; $i < 20; $i++) {
            $durations[] = $this->timed(fn () => $this->getJson('/api/v2/aliases?limit=25')->assertOk()->assertJsonPath('meta.total', 80));
        }

        $this->assertLessThan(1.0, self::p95($durations), sprintf('listAliases as a domain administrator: p95 %.3f s', self::p95($durations)));
    }
}
