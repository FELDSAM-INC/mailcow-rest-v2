<?php

namespace Tests\Feature\Dkim;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Dkim\DkimFixtures;
use Tests\Support\Dkim\DkimTestHelpers;
use Tests\TestCase;

/**
 * Feature 010 T101 (SC-012, research R8): a page of 25 DKIM entries answers within 1 s at p95 on a
 * server with 5,000 domains and 5,000 alias domains with their 2,048-bit public keys, with the
 * default sort and sorted by key length.
 *
 * As in feature 004's latency test, the rows are committed and removed again in tearDown(): inside
 * an open transaction InnoDB checks the visibility of every uncommitted row.
 */
#[Group('performance')]
class DkimListPerformanceTest extends TestCase
{
    use DkimTestHelpers;

    private const ROWS = 5000;

    /** @var list<string> committed rows instead of one transaction per test (see the class comment) */
    protected array $connectionsToTransact = [];

    private bool $seeded = false;

    protected function tearDown(): void
    {
        if ($this->seeded) {
            DB::table('alias_domain')->where('alias_domain', 'like', 'perfalias%')->delete();
            DB::table('domain')->where('domain', 'like', 'perf%')->delete();
            DB::table('admin')->where('username', 'admin')->delete();
        }

        parent::tearDown();
    }

    private function seedKeys(): void
    {
        $this->seeded = true;
        $domains = $aliases = [];

        for ($i = 0; $i < self::ROWS; $i++) {
            $domains[] = ['domain' => "perf{$i}.example", 'description' => '', 'aliases' => 400, 'mailboxes' => 10, 'defquota' => 1024, 'maxquota' => 10240, 'quota' => 10240, 'backupmx' => 0, 'gal' => 1, 'active' => 1, 'relay_unknown_only' => 0, 'relay_all_recipients' => 0];
            $aliases[] = ['alias_domain' => "perfalias{$i}.example", 'target_domain' => "perf{$i}.example", 'active' => 1];

            if (count($domains) === 1000) {
                DB::table('domain')->insert($domains);
                DB::table('alias_domain')->insert($aliases);
                $domains = $aliases = [];
            }
        }

        $redis = $this->redis();
        $keys = array_values(DkimFixtures::rsa());

        foreach (array_chunk(range(0, self::ROWS - 1), 500) as $chunk) {
            $pipeline = $redis->multi(\Redis::PIPELINE);

            foreach ($chunk as $i) {
                // Every fourth domain has no key (missing); key lengths vary over the fixtures.
                if ($i % 4 !== 0) {
                    $pair = $keys[$i % 3 === 0 ? 1 : $i % 5];
                    $pipeline->hSet('DKIM_PUB_KEYS', "perf{$i}.example", $pair['public']);
                    $pipeline->hSet('DKIM_SELECTORS', "perf{$i}.example", 'dkim');
                    $pipeline->hSet('DKIM_PRIV_KEYS', "dkim.perf{$i}.example", 'x');
                }

                $pipeline->hSet('DKIM_PUB_KEYS', "perfalias{$i}.example", DkimFixtures::RSA_2048_PUBLIC);
                $pipeline->hSet('DKIM_SELECTORS', "perfalias{$i}.example", 'dkim');
            }

            $pipeline->exec();
        }
    }

    public function test_a_page_of_25_entries_within_one_second_at_the_95th_percentile(): void
    {
        $this->seedKeys();
        $this->asAdmin();

        foreach (['/api/v2/dkim-keys', '/api/v2/dkim-keys?sort=keyLength', '/api/v2/dkim-keys?state=missing&q=perf1', '/api/v2/dkim-keys?complete=false&limit=25'] as $uri) {
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

        $this->assertSame(2 * self::ROWS, $this->getJson('/api/v2/dkim-keys?limit=1')->json('meta.total'));
    }
}
