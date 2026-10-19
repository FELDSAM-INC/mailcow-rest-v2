<?php

namespace App\Clients\SharedStore;

use Closure;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\DB;
use Redis;
use RedisException;
use RuntimeException;
use Throwable;

/**
 * mailcow's own Redis keys (connection `mailcow`, database 0, no prefix), shared by every feature
 * that writes them (feature 002 research D8, D17): `DOMAIN_MAP`, `RL_VALUE`, the DKIM hashes and
 * so on.
 *
 * Hash writes run in one MULTI/EXEC and return an undo action that restores the previous values
 * (fields that did not exist are removed again), so that a caller can write inside its database
 * transaction and compensate when the transaction fails. Every failure raises
 * SharedStoreUnavailable.
 */
class SharedStore
{
    public function __construct(private readonly RedisFactory $redis) {}

    /** Raises SharedStoreUnavailable unless the store answers. */
    public function ping(): void
    {
        $this->run(fn (Redis $redis): mixed => $redis->ping());
    }

    /**
     * Sets hash fields in one transaction.
     *
     * @param  array<string, string>  $fields  field => value
     * @return Closure(): void the undo action
     */
    public function setFields(string $key, array $fields): Closure
    {
        $names = array_map('strval', array_keys($fields));

        $previous = $this->run(function (Redis $redis) use ($key, $fields, $names): array {
            $previous = $names === [] ? [] : $this->hmget($redis, $key, $names);
            $transaction = $redis->multi();

            foreach ($fields as $field => $value) {
                $transaction->hSet($key, (string) $field, $value);
            }

            $this->exec($transaction);

            return $previous;
        });

        return fn () => $this->restore([$key => $previous]);
    }

    /**
     * Deletes hash fields of several hashes in one transaction.
     *
     * @param  array<string, list<string>>  $keyFields  hash => fields
     * @return Closure(): void the undo action
     */
    public function deleteFields(array $keyFields): Closure
    {
        $previous = $this->run(function (Redis $redis) use ($keyFields): array {
            $previous = [];

            foreach ($keyFields as $key => $fields) {
                $previous[$key] = $fields === [] ? [] : $this->hmget($redis, $key, $fields);
            }

            $transaction = $redis->multi();

            foreach ($keyFields as $key => $fields) {
                foreach ($fields as $field) {
                    $transaction->hDel($key, $field);
                }
            }

            $this->exec($transaction);

            return $previous;
        });

        return fn () => $this->restore($previous);
    }

    public function getField(string $key, string $field): ?string
    {
        return $this->run(function (Redis $redis) use ($key, $field): ?string {
            $value = $redis->hGet($key, $field);

            return is_string($value) ? $value : null;
        });
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, string|null> field => value
     */
    public function getFields(string $key, array $fields): array
    {
        if ($fields === []) {
            return [];
        }

        return $this->run(fn (Redis $redis): array => $this->hmget($redis, $key, $fields));
    }

    public function hasField(string $key, string $field): bool
    {
        return $this->run(fn (Redis $redis): bool => (bool) $redis->hExists($key, $field));
    }

    /**
     * An optimistic transaction over plain keys (feature 006 research R8): WATCH the keys, let the
     * plan read them and decide, then MULTI with the plan's writes and EXEC. When another client
     * changed a watched key meanwhile, EXEC fails and the plan runs again on the fresh values, at
     * most $attempts times in all; then SharedStoreUnavailable. The plan returns `[writes, result]`,
     * where `writes` (null: nothing to write) queues commands on the MULTI client. An optional
     * record (an audit record) is written in a database transaction before EXEC and committed after
     * it; a failed EXEC rolls it back, and a failed record writes nothing.
     *
     * @template TResult
     *
     * @param  list<string>  $keys
     * @param  Closure(Redis): array{0: (Closure(Redis): mixed)|null, 1: TResult}  $plan
     * @param  (Closure(TResult): void)|null  $record
     * @return array{0: TResult, 1: bool} the plan's result, and whether the record's commit succeeded
     */
    public function transactionWatching(array $keys, Closure $plan, ?Closure $record = null, int $attempts = 2): array
    {
        return $this->run(function (Redis $redis) use ($keys, $plan, $record, $attempts): array {
            for ($attempt = 1; $attempt <= max(1, $attempts); $attempt++) {
                $redis->watch($keys);

                try {
                    [$writes, $result] = $plan($redis);
                } catch (Throwable $e) {
                    $redis->unwatch();

                    throw $e;
                }

                if ($writes === null) {
                    $redis->unwatch();

                    return [$result, true];
                }

                $multi = $redis->multi();
                $writes($multi);
                DB::beginTransaction();

                try {
                    if ($record !== null) {
                        $record($result);
                    }
                } catch (Throwable $e) {
                    DB::rollBack();
                    $redis->discard();

                    throw $e;
                }

                if (! is_array($redis->exec())) {
                    DB::rollBack();

                    continue;
                }

                try {
                    DB::commit();
                    $committed = true;
                } catch (Throwable) {
                    $committed = false;
                }

                return [$result, $committed];
            }

            throw new SharedStoreUnavailable('mailcow\'s shared store kept changing while the change was saved.');
        });
    }

    /**
     * Runs a Lua script atomically (feature 006: App\Support\Redis\LuaScripts) and returns its
     * reply. A script error is a defect, not an outage: it raises a RuntimeException.
     *
     * @param  list<string>  $keys
     * @param  list<string>  $arguments
     */
    public function evaluate(string $script, array $keys, array $arguments = []): mixed
    {
        return $this->run(function (Redis $redis) use ($script, $keys, $arguments): mixed {
            $redis->clearLastError();
            $result = $redis->eval($script, [...$keys, ...$arguments], count($keys));
            $error = $redis->getLastError();

            if ($result === false && is_string($error)) {
                throw new RuntimeException('A shared store script failed: '.$error);
            }

            return $result;
        });
    }

    /**
     * Runs an operation on the raw client, mapping every Redis failure to SharedStoreUnavailable.
     *
     * @template T
     *
     * @param  callable(Redis): T  $operation
     * @return T
     */
    public function run(callable $operation): mixed
    {
        try {
            $client = $this->redis->connection('mailcow')->client();

            if (! $client instanceof Redis) {
                throw new SharedStoreUnavailable('The mailcow Redis connection does not use phpredis.');
            }

            return $operation($client);
        } catch (RedisException $e) {
            throw new SharedStoreUnavailable('mailcow\'s shared store is unavailable: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * @param  array<string, array<string, string|null>>  $previous  hash => field => old value
     */
    private function restore(array $previous): void
    {
        $this->run(function (Redis $redis) use ($previous): void {
            $transaction = $redis->multi();

            foreach ($previous as $key => $fields) {
                foreach ($fields as $field => $value) {
                    $value === null ? $transaction->hDel($key, (string) $field) : $transaction->hSet($key, (string) $field, $value);
                }
            }

            $this->exec($transaction);
        });
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, string|null>
     */
    private function hmget(Redis $redis, string $key, array $fields): array
    {
        $values = $redis->hMGet($key, $fields);
        $result = [];

        foreach ($fields as $field) {
            $value = is_array($values) ? ($values[$field] ?? false) : false;
            $result[$field] = is_string($value) ? $value : null;
        }

        return $result;
    }

    private function exec(mixed $transaction): void
    {
        $result = $transaction instanceof Redis ? $transaction->exec() : false;

        if (! is_array($result)) {
            throw new SharedStoreUnavailable('mailcow\'s shared store refused the transaction.');
        }
    }
}
