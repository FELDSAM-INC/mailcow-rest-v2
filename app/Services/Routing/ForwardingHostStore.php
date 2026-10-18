<?php

namespace App\Services\Routing;

use App\Clients\SharedStore\SharedStore;
use App\Clients\SharedStore\SharedStoreUnavailable;
use Redis;

/**
 * The forwarding hosts in mailcow's Redis (feature 009 FR-078–FR-082, research R17), on 002's
 * SharedStore (connection `mailcow`, database 0, no prefix): `WHITELISTED_FWD_HOST` maps an address
 * to its source; `KEEP_SPAM` holds the addresses whose spam filter is disabled. Reads are two
 * `HGETALL` calls; every write is one `MULTI`/`EXEC` over both hashes, so that it is applied
 * completely or not at all. A caller takes a snapshot of the touched fields before writing and
 * restores it when its database transaction fails after the write. Every Redis failure raises
 * SharedStoreUnavailable (`503 temporary-failure`).
 */
class ForwardingHostStore
{
    public const SOURCES = 'WHITELISTED_FWD_HOST';

    public const KEEP_SPAM = 'KEEP_SPAM';

    public function __construct(private readonly SharedStore $store) {}

    /**
     * Every entry: address => [source, keepSpam].
     *
     * @return array<string, array{source: string, keepSpam: bool}>
     */
    public function all(): array
    {
        return $this->store->run(function (Redis $redis): array {
            $sources = $redis->hGetAll(self::SOURCES);
            $keep = $redis->hGetAll(self::KEEP_SPAM);
            $entries = [];

            foreach (is_array($sources) ? $sources : [] as $address => $source) {
                $entries[(string) $address] = ['source' => (string) $source, 'keepSpam' => is_array($keep) && array_key_exists($address, $keep)];
            }

            return $entries;
        });
    }

    /**
     * The current values of the given addresses, to restore them later.
     *
     * @param  list<string>  $addresses
     * @return array<string, array{source: string|null, keepSpam: string|null}>
     */
    public function snapshot(array $addresses): array
    {
        if ($addresses === []) {
            return [];
        }

        return $this->store->run(function (Redis $redis) use ($addresses): array {
            $sources = $redis->hMGet(self::SOURCES, $addresses);
            $keep = $redis->hMGet(self::KEEP_SPAM, $addresses);
            $snapshot = [];

            foreach ($addresses as $address) {
                $source = is_array($sources) ? ($sources[$address] ?? false) : false;
                $flag = is_array($keep) ? ($keep[$address] ?? false) : false;
                $snapshot[$address] = ['source' => is_string($source) ? $source : null, 'keepSpam' => is_string($flag) ? $flag : null];
            }

            return $snapshot;
        });
    }

    /**
     * One transaction: sets the sources, sets or clears `KEEP_SPAM`, and removes deleted entries.
     *
     * @param  array<string, string>  $sources  address => source to set
     * @param  array<string, bool>  $keepSpam  address => whether the spam filter is disabled
     * @param  list<string>  $deleted  addresses to remove from both hashes
     */
    public function apply(array $sources, array $keepSpam, array $deleted = []): void
    {
        $this->store->run(function (Redis $redis) use ($sources, $keepSpam, $deleted): void {
            $transaction = $redis->multi();

            foreach ($sources as $address => $source) {
                $transaction->hSet(self::SOURCES, (string) $address, $source);
            }

            foreach ($keepSpam as $address => $keep) {
                $keep ? $transaction->hSet(self::KEEP_SPAM, (string) $address, '1') : $transaction->hDel(self::KEEP_SPAM, (string) $address);
            }

            foreach ($deleted as $address) {
                $transaction->hDel(self::SOURCES, $address);
                $transaction->hDel(self::KEEP_SPAM, $address);
            }

            self::exec($transaction);
        });
    }

    /**
     * Writes a snapshot back in one transaction (fields that did not exist are removed).
     *
     * @param  array<string, array{source: string|null, keepSpam: string|null}>  $snapshot
     */
    public function restore(array $snapshot): void
    {
        if ($snapshot === []) {
            return;
        }

        $this->store->run(function (Redis $redis) use ($snapshot): void {
            $transaction = $redis->multi();

            foreach ($snapshot as $address => ['source' => $source, 'keepSpam' => $keep]) {
                $source === null ? $transaction->hDel(self::SOURCES, $address) : $transaction->hSet(self::SOURCES, $address, $source);
                $keep === null ? $transaction->hDel(self::KEEP_SPAM, $address) : $transaction->hSet(self::KEEP_SPAM, $address, $keep);
            }

            self::exec($transaction);
        });
    }

    private static function exec(mixed $transaction): void
    {
        $result = $transaction instanceof Redis ? $transaction->exec() : false;

        if (! is_array($result)) {
            throw new SharedStoreUnavailable('mailcow\'s shared store refused the transaction.');
        }
    }
}
