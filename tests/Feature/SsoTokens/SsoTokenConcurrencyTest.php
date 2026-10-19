<?php

namespace Tests\Feature\SsoTokens;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Feature 007 T080 (edge case "same token twice at the same moment", FR-060): in 200 rounds the same
 * token is submitted at the same moment to this API and to legacy's domain administrator login,
 * each in its own PHP process with its own database connection; exactly one sign-in succeeds in
 * every round. Rows are committed outside the test's transaction and removed by the test.
 */
#[Group('concurrency')]
class SsoTokenConcurrencyTest extends TestCase
{
    private const ROUNDS = 200;

    private Connection $observer;

    /** @var array<string, Process> */
    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.observer' => config('database.connections.mysql')]);
        $this->observer = DB::connection('observer');
        $this->observer->table('admin')->where('username', 'race-reseller')->delete();
        $this->observer->table('admin')->insert(['username' => 'race-reseller', 'password' => '{PLAIN}x', 'superadmin' => 0, 'active' => 1,
            'attributes' => '{"force_tfa": "0", "force_pw_update": "0"}', 'created' => DB::raw('NOW() - INTERVAL 1 DAY')]);
    }

    protected function tearDown(): void
    {
        foreach (array_keys($this->workers) as $name) {
            Redis::connection('restv2')->rpush("race:{$name}", json_encode(['stop' => true]));
            $this->workers[$name]->wait();
        }

        $this->observer->table('da_sso')->where('username', 'race-reseller')->delete();
        $this->observer->table('admin')->where('username', 'race-reseller')->delete();
        DB::purge('observer');

        parent::tearDown();
    }

    private function pop(string $list, string $worker): ?string
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

    public function test_exactly_one_of_two_simultaneous_sign_ins_succeeds(): void
    {
        $php = (new PhpExecutableFinder)->find(false) ?: 'php';

        foreach (['api', 'legacy'] as $name) {
            $this->workers[$name] = new Process([$php, 'tests/bin/sso-token-race.php', $name], base_path(), ['APP_ENV' => 'testing']);
            $this->workers[$name]->setTimeout(600);
            $this->workers[$name]->start();
            $this->assertNotNull($this->pop("race:{$name}:ready", $name), "Worker {$name} did not start.");
        }

        for ($round = 1; $round <= self::ROUNDS; $round++) {
            $token = implode('-', array_map(fn (): string => strtoupper(bin2hex(random_bytes(3))), range(1, 5)));
            $this->observer->table('da_sso')->insert(['username' => 'race-reseller', 'token' => $token]);
            $start = microtime(true) + 0.02;

            foreach (['api', 'legacy'] as $name) {
                Redis::connection('restv2')->rpush("race:{$name}", json_encode(['round' => $round, 'start' => $start, 'token' => $token]));
            }

            $results = [];

            foreach (['api', 'legacy'] as $name) {
                $answer = $this->pop("race:{$name}:results", $name);
                $this->assertNotNull($answer, "No answer of {$name} in round {$round}.");
                $results[] = (string) json_decode($answer, true)['result'];
            }

            sort($results);
            $this->assertSame(['refused', 'signedIn'], $results, "Round {$round}: ".implode(', ', $results));
        }
    }
}
