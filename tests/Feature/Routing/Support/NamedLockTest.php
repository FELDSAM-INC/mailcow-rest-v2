<?php

namespace Tests\Feature\Routing\Support;

use App\Exceptions\ProblemException;
use App\Services\Routing\RoutingLock;
use App\Support\Http\ProblemType;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Tests\TestCase;

/**
 * Feature 009 T012 (FR-083, FR-084, research R9): a routing write runs under the MariaDB named lock
 * of its table (003's App\Support\Database\NamedLock) and in one transaction; a lock that another
 * session holds longer than `restv2.routing.lock_timeout` answers `503 temporary-failure` without
 * running anything.
 */
class NamedLockTest extends TestCase
{
    private const LOCK = 'restv2:routing:transports';

    private ?PDO $other = null;

    protected function tearDown(): void
    {
        if ($this->other !== null) {
            $this->other->query("SELECT RELEASE_LOCK('".self::LOCK."')");
            $this->other = null;
        }

        parent::tearDown();
    }

    public function test_the_code_runs_under_the_lock_in_a_transaction(): void
    {
        $level = DB::transactionLevel();

        $result = app(RoutingLock::class)->run(self::LOCK, function () use ($level): array {
            return [
                'held' => (int) DB::selectOne('SELECT IS_USED_LOCK(?) = CONNECTION_ID() AS held', [self::LOCK])->held,
                'level' => DB::transactionLevel() - $level,
            ];
        });

        $this->assertSame(['held' => 1, 'level' => 1], $result);
        $this->assertNull(DB::selectOne('SELECT IS_USED_LOCK(?) AS holder', [self::LOCK])->holder);
    }

    public function test_the_lock_is_released_and_the_writes_rolled_back_when_the_code_throws(): void
    {
        $thrown = null;

        try {
            app(RoutingLock::class)->run(self::LOCK, function (): never {
                DB::table('transports')->insert(['nexthop' => '[x.example]:25', 'destination' => 'x.example', 'is_mx_based' => 0, 'username' => '', 'password' => '', 'active' => 0]);

                throw new RuntimeException('boom');
            });
        } catch (RuntimeException $e) {
            $thrown = $e->getMessage();
        }

        $this->assertSame('boom', $thrown);
        $this->assertNull(DB::selectOne('SELECT IS_USED_LOCK(?) AS holder', [self::LOCK])->holder);
        $this->assertFalse(DB::table('transports')->where('destination', 'x.example')->exists());
    }

    public function test_a_lock_held_by_another_session_answers_temporary_failure_after_the_timeout(): void
    {
        config(['restv2.routing.lock_timeout' => 1]);
        $this->holdLockInAnotherSession();
        $ran = false;
        $started = microtime(true);

        try {
            app(RoutingLock::class)->run(self::LOCK, function () use (&$ran): void {
                $ran = true;
            });
            $this->fail('The lock was acquired.');
        } catch (ProblemException $e) {
            $this->assertSame(ProblemType::TemporaryFailure, $e->type);
            $this->assertArrayHasKey('Retry-After', $e->headers);
            $this->assertSame('database', $e->extensions['dependency'] ?? null);
        }

        $elapsed = microtime(true) - $started;
        $this->assertFalse($ran);
        $this->assertGreaterThanOrEqual(0.9, $elapsed);
        $this->assertLessThan(5.0, $elapsed);

        $this->other?->query("SELECT RELEASE_LOCK('".self::LOCK."')");

        $this->assertSame('done', app(RoutingLock::class)->run(self::LOCK, fn (): string => 'done'));
    }

    private function holdLockInAnotherSession(): void
    {
        $config = config('database.connections.mysql');
        $this->other = new PDO(
            "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
            (string) $config['username'],
            (string) $config['password'],
        );
        $statement = $this->other->query("SELECT GET_LOCK('".self::LOCK."', 0)");
        $acquired = $statement === false ? null : $statement->fetchColumn();
        $this->assertSame(1, (int) $acquired);
    }
}
