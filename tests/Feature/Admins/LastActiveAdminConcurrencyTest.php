<?php

namespace Tests\Feature\Admins;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Feature 007 T072 (AS US4-4, SC-003, research R8): in 1,000 rounds, two active administrators
 * deactivate or delete each other at the same moment, each in its own PHP process with its own
 * database connection; after every round at least one administrator is active and at most one of
 * the two requests succeeded. The rows are committed outside the test's transaction (an observer
 * connection) and removed by the test itself.
 */
#[Group('concurrency')]
class LastActiveAdminConcurrencyTest extends TestCase
{
    private const ROUNDS = 1000;

    private const ACTIONS = [['deactivate', 'deactivate'], ['delete', 'delete'], ['deactivate', 'delete'], ['delete', 'deactivate']];

    private Connection $observer;

    /** @var list<Process> */
    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.observer' => config('database.connections.mysql')]);
        $this->observer = DB::connection('observer');
        $this->assertSame(0, $this->observer->table('admin')->where('superadmin', 1)->count(), 'The race needs a database without committed administrators.');
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $i => $worker) {
            Redis::connection('restv2')->rpush("race:w{$i}", json_encode(['stop' => true]));
            $worker->wait();
        }

        $this->observer->table('admin')->whereIn('username', ['race-a', 'race-b'])->delete();
        $this->observer->table('restv2_audit_events')->whereIn('target_id', ['race-a', 'race-b'])->delete();
        DB::purge('observer');

        parent::tearDown();
    }

    /**
     * The next element of a list, polled in short blocking steps (Redis reads time out after 5 s),
     * or null after 60 s or when the worker exited.
     */
    private function pop(string $list, int $worker): ?string
    {
        $deadline = microtime(true) + 60;

        while (microtime(true) < $deadline) {
            $answer = Redis::connection('restv2')->blpop([$list], 2);

            if (is_array($answer) && isset($answer[1])) {
                return (string) $answer[1];
            }

            if (! $this->workers[$worker]->isRunning()) {
                $this->fail("Worker {$worker} exited: ".$this->workers[$worker]->getErrorOutput().$this->workers[$worker]->getOutput());
            }
        }

        return null;
    }

    public function test_concurrent_deactivations_and_deletions_never_remove_the_last_active_administrator(): void
    {
        $redis = Redis::connection('restv2');
        $php = (new PhpExecutableFinder)->find(false) ?: 'php';

        foreach ([0, 1] as $i) {
            $this->workers[$i] = new Process([$php, 'tests/bin/admin-race.php', "w{$i}"], base_path(), ['APP_ENV' => 'testing']);
            $this->workers[$i]->setTimeout(900);
            $this->workers[$i]->start();
            $this->assertNotNull($this->pop("race:w{$i}:ready", $i), 'Worker '.$i.' did not start.');
        }

        $successes = 0;

        for ($round = 1; $round <= self::ROUNDS; $round++) {
            $this->observer->table('admin')->whereIn('username', ['race-a', 'race-b'])->delete();
            $this->observer->table('admin')->insert([
                ['username' => 'race-a', 'password' => '{PLAIN}x', 'superadmin' => 1, 'active' => 1, 'attributes' => '{"force_tfa": "0", "force_pw_update": "0"}'],
                ['username' => 'race-b', 'password' => '{PLAIN}x', 'superadmin' => 1, 'active' => 1, 'attributes' => '{"force_tfa": "0", "force_pw_update": "0"}'],
            ]);
            [$first, $second] = self::ACTIONS[$round % count(self::ACTIONS)];
            $start = microtime(true) + 0.02;
            $redis->rpush('race:w0', json_encode(['round' => $round, 'start' => $start, 'actor' => 'race-a', 'target' => 'race-b', 'action' => $first]));
            $redis->rpush('race:w1', json_encode(['round' => $round, 'start' => $start, 'actor' => 'race-b', 'target' => 'race-a', 'action' => $second]));

            $results = [];

            foreach ([0, 1] as $i) {
                $answer = $this->pop("race:w{$i}:results", $i);
                $this->assertNotNull($answer, "No answer of worker {$i} in round {$round}.");
                $results[] = (string) json_decode($answer, true)['result'];
            }

            foreach ($results as $result) {
                $this->assertContains($result, ['done', 'last-active-admin'], "Round {$round}: {$result}");
            }

            $done = count(array_keys($results, 'done', true));
            $this->assertLessThanOrEqual(1, $done, "Round {$round}: both requests succeeded.");
            $this->assertGreaterThanOrEqual(1, $this->observer->table('admin')->where('superadmin', 1)->where('active', 1)->count(), "Round {$round} left no active administrator.");
            $successes += $done;
        }

        $this->assertSame(self::ROUNDS, $successes, 'Each round must let exactly one of the two changes through.');
    }
}
