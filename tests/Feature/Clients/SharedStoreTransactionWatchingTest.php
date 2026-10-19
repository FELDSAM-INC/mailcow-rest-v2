<?php

namespace Tests\Feature\Clients;

use App\Clients\SharedStore\SharedStore;
use App\Clients\SharedStore\SharedStoreUnavailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Redis as PhpRedis;
use RuntimeException;
use Tests\TestCase;

/**
 * Feature 006 T001 (research R8): the optimistic WATCH/MULTI/EXEC variant of mailcow's shared
 * store. The plan reads the watched keys and returns the writes; a change of a watched key by
 * another client between the read and EXEC makes the attempt fail, and the plan runs again on the
 * fresh values. A record written in the database transaction before EXEC is rolled back with a
 * failed attempt.
 */
class SharedStoreTransactionWatchingTest extends TestCase
{
    private function store(): SharedStore
    {
        return $this->app->make(SharedStore::class);
    }

    private function raw(): PhpRedis
    {
        $client = Redis::connection('mailcow')->client();
        $this->assertInstanceOf(PhpRedis::class, $client);

        return $client;
    }

    /** A second client of the same database, as another process would be. */
    private function otherClient(): PhpRedis
    {
        $config = config('database.redis.mailcow');
        $client = new PhpRedis;
        $client->connect((string) $config['host'], (int) $config['port']);

        if (($config['password'] ?? null) !== null) {
            $client->auth((string) $config['password']);
        }

        $client->select((int) $config['database']);

        return $client;
    }

    public function test_the_writes_of_the_plan_are_executed(): void
    {
        $this->raw()->set('Q_MAX_AGE', '30');

        [$result, $committed] = $this->store()->transactionWatching(['Q_MAX_AGE', 'Q_MAX_SIZE'], function (PhpRedis $redis): array {
            $old = $redis->get('Q_MAX_AGE');

            return [fn (PhpRedis $multi) => $multi->set('Q_MAX_AGE', '60')->set('Q_MAX_SIZE', '15'), $old];
        });

        $this->assertSame('30', $result);
        $this->assertTrue($committed);
        $this->assertSame('60', $this->raw()->get('Q_MAX_AGE'));
        $this->assertSame('15', $this->raw()->get('Q_MAX_SIZE'));
    }

    public function test_a_plan_without_writes_changes_nothing(): void
    {
        [$result] = $this->store()->transactionWatching(['Q_MAX_AGE'], fn (PhpRedis $redis): array => [null, 'unchanged']);

        $this->assertSame('unchanged', $result);
        $this->assertSame(0, $this->raw()->exists('Q_MAX_AGE'));
    }

    public function test_a_concurrent_change_runs_the_plan_again_on_the_fresh_values(): void
    {
        $this->raw()->set('Q_MAX_AGE', '30');
        $other = $this->otherClient();
        $seen = [];

        [$result] = $this->store()->transactionWatching(['Q_MAX_AGE'], function (PhpRedis $redis) use ($other, &$seen): array {
            $seen[] = $value = (string) $redis->get('Q_MAX_AGE');

            if (count($seen) === 1) {
                $other->set('Q_MAX_AGE', '45');
            }

            return [fn (PhpRedis $multi) => $multi->set('Q_MAX_AGE', ((int) $value + 1).''), $value];
        });

        $this->assertSame(['30', '45'], $seen);
        $this->assertSame('45', $result);
        $this->assertSame('46', $this->raw()->get('Q_MAX_AGE'));
    }

    public function test_a_store_that_keeps_changing_fails_after_the_attempts(): void
    {
        $other = $this->otherClient();
        $attempts = 0;

        try {
            $this->store()->transactionWatching(['Q_MAX_AGE'], function (PhpRedis $redis) use ($other, &$attempts): array {
                $attempts++;
                $other->set('Q_MAX_AGE', (string) $attempts);

                return [fn (PhpRedis $multi) => $multi->set('Q_MAX_AGE', 'mine'), null];
            }, attempts: 2);
            $this->fail('Expected SharedStoreUnavailable.');
        } catch (SharedStoreUnavailable) {
            $this->assertSame(2, $attempts);
            $this->assertSame('2', $this->raw()->get('Q_MAX_AGE'));
        }
    }

    public function test_the_record_is_written_before_exec_and_a_failed_record_writes_nothing(): void
    {
        $this->store()->transactionWatching(['Q_SUBJ'], fn (PhpRedis $redis): array => [fn (PhpRedis $multi) => $multi->set('Q_SUBJ', 'Hello'), 'x'], function (mixed $result): void {
            $this->assertSame('x', $result);
            $this->assertGreaterThan(0, DB::transactionLevel());
        });
        $this->assertSame('Hello', $this->raw()->get('Q_SUBJ'));

        try {
            $this->store()->transactionWatching(['Q_SUBJ'], fn (PhpRedis $redis): array => [fn (PhpRedis $multi) => $multi->set('Q_SUBJ', 'Bye'), null], function (): never {
                throw new RuntimeException('audit failed');
            });
            $this->fail('Expected the record failure.');
        } catch (RuntimeException $e) {
            $this->assertSame('audit failed', $e->getMessage());
        }

        $this->assertSame('Hello', $this->raw()->get('Q_SUBJ'));
    }

    public function test_an_unreachable_store_is_unavailable(): void
    {
        config(['database.redis.mailcow.port' => 1]);
        $this->app->forgetInstance('redis');
        Redis::clearResolvedInstances();

        $this->expectException(SharedStoreUnavailable::class);
        $this->store()->transactionWatching(['Q_SUBJ'], fn (PhpRedis $redis): array => [null, null]);
    }
}
