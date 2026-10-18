<?php

namespace App\Clients\SharedStore;

use Closure;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Redis;
use RedisException;

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
