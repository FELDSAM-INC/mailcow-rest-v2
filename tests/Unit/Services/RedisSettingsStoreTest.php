<?php

namespace Tests\Unit\Services;

use App\Clients\SharedStore\SharedStoreUnavailable;
use App\Services\Settings\RedisSettingsStore;
use App\Services\Settings\SettingsWrite;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis as RedisFacade;
use Redis;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Tests\TestCase;

/**
 * Feature 013 T010 (research R1; FR-007 to FR-009, FR-011, FR-091): settings in mailcow's Redis are
 * written in one optimistic transaction. Built on feature 006's SharedStore::transactionWatching():
 * WATCH, read, compare the version tag, plan, audit record inside the database transaction, MULTI /
 * EXEC, commit; a concurrent change retries on fresh values, an outdated tag answers 412, and a
 * failed commit restores the old values.
 */
class RedisSettingsStoreTest extends TestCase
{
    private const KEYS = ['TITLE_NAME', 'MAIN_NAME'];

    private function store(): RedisSettingsStore
    {
        return $this->app->make(RedisSettingsStore::class);
    }

    private function redis(): Redis
    {
        $client = RedisFacade::connection('mailcow')->client();
        $this->assertInstanceOf(Redis::class, $client);

        return $client;
    }

    /**
     * A second, independent client of the same database: what another process (legacy) sees.
     */
    private function otherClient(): Redis
    {
        $config = config('database.redis.mailcow');
        $client = new Redis;
        $client->connect((string) $config['host'], (int) $config['port']);
        $client->auth((string) $config['password']);
        $client->select((int) $config['database']);

        return $client;
    }

    /**
     * @return array<string, string|null>
     */
    private static function raw(Redis $redis): array
    {
        $values = $redis->mget(self::KEYS);

        return array_combine(self::KEYS, array_map(fn (mixed $v): ?string => is_string($v) ? $v : null, is_array($values) ? $values : [null, null]));
    }

    /**
     * @param  array<string, string>  $values
     */
    private function write(array $values, ?string $ifMatch = null, ?\Closure $audit = null, ?\Closure $beforePlan = null): mixed
    {
        return $this->store()->transaction(
            self::KEYS,
            $ifMatch,
            fn (Redis $redis): array => self::raw($redis),
            fn (array $raw): string => '"'.sha1((string) json_encode($raw)).'"',
            function (array $raw) use ($values, $beforePlan): SettingsWrite {
                if ($beforePlan !== null) {
                    $beforePlan($raw);
                }

                return new SettingsWrite(
                    function (Redis $multi) use ($values): void {
                        foreach ($values as $key => $value) {
                            $multi->set($key, $value);
                        }
                    },
                    function (Redis $multi) use ($raw): void {
                        foreach ($raw as $key => $value) {
                            $value === null ? $multi->del($key) : $multi->set($key, $value);
                        }
                    },
                    ['old' => $raw, 'new' => $values],
                );
            },
            $audit ?? function (): void {},
        );
    }

    public function test_every_key_is_written_in_one_transaction_and_a_reader_never_sees_half(): void
    {
        $this->redis()->set('TITLE_NAME', 'old title');
        $other = $this->otherClient();
        $seenDuringAudit = null;

        $result = $this->write(['TITLE_NAME' => 'new title', 'MAIN_NAME' => 'new name'], audit: function () use ($other, &$seenDuringAudit): void {
            $seenDuringAudit = self::raw($other);
        });

        $this->assertSame(['TITLE_NAME' => 'old title', 'MAIN_NAME' => null], $seenDuringAudit);
        $this->assertSame(['TITLE_NAME' => 'new title', 'MAIN_NAME' => 'new name'], self::raw($other));
        $this->assertSame(['old' => ['TITLE_NAME' => 'old title', 'MAIN_NAME' => null], 'new' => ['TITLE_NAME' => 'new title', 'MAIN_NAME' => 'new name']], $result);
    }

    public function test_the_audit_record_is_written_in_the_database_transaction_of_the_change(): void
    {
        $level = null;

        $this->write(['TITLE_NAME' => 'x'], audit: function () use (&$level): void {
            $level = DB::transactionLevel();
        });

        $this->assertGreaterThan(1, (int) $level, 'The audit callback runs inside its own (nested) transaction.');
    }

    public function test_a_concurrent_change_between_read_and_exec_retries_with_fresh_values(): void
    {
        $this->redis()->set('MAIN_NAME', 'first');
        $other = $this->otherClient();
        $seen = [];

        $result = $this->write(['TITLE_NAME' => 'mine'], beforePlan: function (array $raw) use ($other, &$seen): void {
            $seen[] = $raw['MAIN_NAME'];

            if (count($seen) === 1) {
                $other->set('MAIN_NAME', 'changed by legacy');
            }
        });

        $this->assertSame(['first', 'changed by legacy'], $seen);
        $this->assertSame('changed by legacy', $this->redis()->get('MAIN_NAME'));
        $this->assertSame('mine', $this->redis()->get('TITLE_NAME'));
        $this->assertIsArray($result);
    }

    public function test_an_outdated_tag_answers_412_and_writes_nothing(): void
    {
        $this->redis()->set('TITLE_NAME', 'old');

        try {
            $this->write(['TITLE_NAME' => 'new'], '"outdated"');
            $this->fail('An outdated tag must be refused.');
        } catch (PreconditionFailedHttpException) {
            $this->assertSame('old', $this->redis()->get('TITLE_NAME'));
        }
    }

    public function test_a_tag_that_became_outdated_during_a_retry_still_answers_412(): void
    {
        $this->redis()->set('MAIN_NAME', 'first');
        $tag = '"'.sha1((string) json_encode(['TITLE_NAME' => null, 'MAIN_NAME' => 'first'])).'"';
        $other = $this->otherClient();
        $calls = 0;

        try {
            $this->write(['TITLE_NAME' => 'mine'], $tag, beforePlan: function () use ($other, &$calls): void {
                if (++$calls === 1) {
                    $other->set('MAIN_NAME', 'changed by legacy');
                }
            });
            $this->fail('The tag no longer matches after the concurrent change.');
        } catch (PreconditionFailedHttpException) {
            $this->assertNull($this->redis()->get('TITLE_NAME') ?: null);
        }
    }

    public function test_three_aborted_transactions_end_in_a_temporary_failure(): void
    {
        $other = $this->otherClient();
        $attempts = 0;

        try {
            $this->write(['TITLE_NAME' => 'mine'], beforePlan: function () use ($other, &$attempts): void {
                $other->set('MAIN_NAME', 'changed '.(++$attempts));
            });
            $this->fail('A store that keeps changing must answer a temporary failure.');
        } catch (SharedStoreUnavailable) {
            $this->assertSame(3, $attempts);
            $this->assertFalse($this->redis()->get('TITLE_NAME'));
        }
    }

    public function test_a_failed_audit_record_writes_nothing(): void
    {
        $this->redis()->set('TITLE_NAME', 'old');

        try {
            $this->write(['TITLE_NAME' => 'new'], audit: fn () => throw new RuntimeException('audit insert failed'));
            $this->fail('A failed audit record must fail the change.');
        } catch (RuntimeException $e) {
            $this->assertSame('audit insert failed', $e->getMessage());
            $this->assertSame('old', $this->redis()->get('TITLE_NAME'));
        }
    }

    public function test_a_failed_commit_after_exec_restores_the_old_values(): void
    {
        $this->redis()->set('TITLE_NAME', 'old');
        $failed = false;
        Event::listen(TransactionCommitted::class, function () use (&$failed): void {
            if (! $failed) {
                $failed = true;

                throw new RuntimeException('commit failed');
            }
        });

        try {
            $this->write(['TITLE_NAME' => 'new', 'MAIN_NAME' => 'added']);
            $this->fail('A failed commit must fail the change.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('restored', $e->getMessage());
            $this->assertSame('old', $this->redis()->get('TITLE_NAME'));
            $this->assertFalse($this->redis()->get('MAIN_NAME'));
        }
    }

    public function test_a_plan_without_writes_changes_nothing_and_records_nothing(): void
    {
        $audited = false;

        $result = $this->store()->transaction(
            self::KEYS,
            null,
            fn (Redis $redis): array => self::raw($redis),
            fn (array $raw): string => '"x"',
            fn (array $raw): ?SettingsWrite => null,
            function () use (&$audited): void {
                $audited = true;
            },
        );

        $this->assertNull($result);
        $this->assertFalse($audited);
    }

    public function test_an_unreachable_store_is_a_temporary_failure(): void
    {
        config(['database.redis.mailcow.port' => 1]);
        $this->app->forgetInstance('redis');
        RedisFacade::clearResolvedInstances();

        $this->expectException(SharedStoreUnavailable::class);

        $this->store()->strings(['TITLE_NAME']);
    }
}
