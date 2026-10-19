<?php

namespace App\Services\Settings;

use App\Clients\SharedStore\SharedStore;
use App\Clients\SharedStore\SharedStoreUnavailable;
use App\Support\Http\ETag;
use Closure;
use Illuminate\Support\Facades\Log;
use Redis;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * Settings that legacy keeps in mailcow's Redis (feature 013 research R1), read and written on the
 * `mailcow` connection with legacy's keys and formats. Built on feature 006's
 * SharedStore::transactionWatching(), which every write goes through:
 *
 *  1. WATCH the resource's keys and read their raw values;
 *  2. compare the version tag with `If-Match` (`412` on mismatch, nothing written);
 *  3. plan the change on the current values (null: nothing to change, nothing audited);
 *  4. insert the audit record in a database transaction, MULTI / EXEC, commit.
 *
 * A concurrent change of a watched key (legacy wrote meanwhile) aborts EXEC, and the steps run again
 * on the fresh values, three times in all, comparing the tag again each time; then the store is
 * reported unavailable (`503 temporary-failure`). When the commit fails after EXEC, the old values
 * are restored and the request fails (`500`), so that no change exists without its audit record.
 * Every Redis failure is SharedStoreUnavailable (`503`, rendered by 001's problem renderer).
 */
class RedisSettingsStore
{
    public const ATTEMPTS = 3;

    public function __construct(private readonly SharedStore $store) {}

    /**
     * Runs a read on the raw client.
     *
     * @template T
     *
     * @param  Closure(Redis): T  $read
     * @return T
     *
     * @throws SharedStoreUnavailable
     */
    public function read(Closure $read): mixed
    {
        return $this->store->run($read);
    }

    /**
     * Raw values of plain keys, null for a missing key.
     *
     * @param  list<string>  $keys
     * @return array<string, string|null>
     *
     * @throws SharedStoreUnavailable
     */
    public function strings(array $keys): array
    {
        return $this->store->run(fn (Redis $redis): array => self::stringsFrom($redis, $keys));
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, string|null>
     */
    public static function stringsFrom(Redis $redis, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        /** @var list<string|false>|false $values */
        $values = $redis->mget($keys);
        $raw = [];

        foreach ($keys as $i => $key) {
            $value = is_array($values) ? ($values[$i] ?? false) : false;
            $raw[$key] = is_string($value) ? $value : null;
        }

        return $raw;
    }

    /**
     * Raw values of hash fields, null for a missing field.
     *
     * @param  list<string>  $fields
     * @return array<string, string|null>
     */
    public static function fieldsFrom(Redis $redis, string $key, array $fields): array
    {
        $values = $redis->hMGet($key, $fields);
        $raw = [];

        foreach ($fields as $field) {
            $value = is_array($values) ? ($values[$field] ?? false) : false;
            $raw[$field] = is_string($value) ? $value : null;
        }

        return $raw;
    }

    /**
     * The optimistic transaction described above. Returns the audit payload of the applied write,
     * or null when the plan found nothing to change.
     *
     * @template TRaw of array
     *
     * @param  list<string>  $keys  the keys to WATCH
     * @param  Closure(Redis): TRaw  $read  the resource's raw values
     * @param  Closure(TRaw): string  $etagOf  the version tag of raw values
     * @param  Closure(TRaw): (SettingsWrite|null)  $plan  the change, or null when nothing changes
     * @param  Closure(mixed): void  $audit  writes the success audit record from the write's payload
     *
     * @throws PreconditionFailedHttpException when $ifMatch is outdated
     * @throws SharedStoreUnavailable
     */
    public function transaction(array $keys, ?string $ifMatch, Closure $read, Closure $etagOf, Closure $plan, Closure $audit): mixed
    {
        /** @var SettingsWrite|null $write */
        [$write, $committed] = $this->store->transactionWatching(
            $keys,
            function (Redis $redis) use ($read, $etagOf, $plan, $ifMatch): array {
                $raw = $read($redis);

                if (ETag::preconditionFails($ifMatch, $etagOf($raw))) {
                    throw new PreconditionFailedHttpException('The settings have changed since they were read.');
                }

                $write = $plan($raw);

                return [$write === null ? null : fn (Redis $multi) => ($write->apply)($multi), $write];
            },
            function (mixed $write) use ($audit): void {
                $audit($write instanceof SettingsWrite ? $write->audit : null);
            },
            self::ATTEMPTS,
        );

        if ($write === null) {
            return null;
        }

        if (! $committed) {
            $this->compensate($write);

            throw new RuntimeException('The audit record of a settings change could not be committed; the previous values were restored.');
        }

        return $write->audit;
    }

    private function compensate(SettingsWrite $write): void
    {
        try {
            $this->store->run(function (Redis $redis) use ($write): void {
                $multi = $redis->multi();
                ($write->restore)($multi);
                $multi->exec();
            });
        } catch (\Throwable $e) {
            Log::error('A settings change could not be undone after its audit record failed: '.$e->getMessage());
        }
    }
}
