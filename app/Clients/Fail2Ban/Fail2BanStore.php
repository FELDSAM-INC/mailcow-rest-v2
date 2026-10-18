<?php

namespace App\Clients\Fail2Ban;

use App\Clients\SharedStore\SharedStore;
use App\Clients\SharedStore\SharedStoreUnavailable;
use App\Exceptions\ProblemException;
use App\Services\Fail2Ban\Fail2BanSettingsValues;
use App\Support\Http\ProblemType;
use Closure;
use Illuminate\Support\Facades\DB;
use Redis;
use Throwable;

/**
 * mailcow's ban service keys in Redis (feature 011 research R18, R20, R22): `F2B_OPTIONS` (with the
 * old separate keys as fallback, like netfilter's refreshF2boptions()), the `F2B_WHITELIST` and
 * `F2B_BLACKLIST` hashes, `F2B_REGEX`, and the ban hashes `F2B_ACTIVE_BANS`, `F2B_QUEUE_UNBAN` and
 * `F2B_PERM_BANS`. 001's Fail2BanReporter (`F2B_CHANNEL`) stays separate.
 *
 * Changes run in an optimistic transaction on one connection: WATCH the keys, read and decide,
 * queue the writes in MULTI, write the audit record in a database transaction, EXEC, commit. A
 * watched key that changed meanwhile (typically the ban service's periodic rewrite of
 * `F2B_OPTIONS`) makes EXEC fail; the change is decided again on the fresh values, up to five
 * times, then the request answers `503`.
 */
class Fail2BanStore
{
    public const OPTIONS = 'F2B_OPTIONS';

    public const ALLOWLIST = 'F2B_WHITELIST';

    public const DENYLIST = 'F2B_BLACKLIST';

    public const RULES = 'F2B_REGEX';

    public const ACTIVE_BANS = 'F2B_ACTIVE_BANS';

    public const UNBAN_QUEUE = 'F2B_QUEUE_UNBAN';

    public const PERMANENT_BANS = 'F2B_PERM_BANS';

    private const ATTEMPTS = 5;

    /** The old separate keys netfilter reads when `F2B_OPTIONS` is absent. */
    private const LEGACY_KEYS = [
        'ban_time' => 'F2B_BAN_TIME',
        'max_ban_time' => 'F2B_MAX_BAN_TIME',
        'ban_time_increment' => 'F2B_BAN_TIME_INCREMENT',
        'max_attempts' => 'F2B_MAX_ATTEMPTS',
        'retry_window' => 'F2B_RETRY_WINDOW',
        'netban_ipv4' => 'F2B_NETBAN_IPV4',
        'netban_ipv6' => 'F2B_NETBAN_IPV6',
    ];

    private ?Closure $afterRead = null;

    public function __construct(private readonly SharedStore $store) {}

    /**
     * Runs $hook once, after the next transaction read its values (tests: a concurrent change).
     */
    public function onceAfterRead(Closure $hook): void
    {
        $this->afterRead = $hook;
    }

    /**
     * @throws SharedStoreUnavailable
     */
    public function settings(): Fail2BanSettingsValues
    {
        return $this->store->run(fn (Redis $redis): Fail2BanSettingsValues => $this->readSettings($redis));
    }

    public function readSettings(Redis $redis): Fail2BanSettingsValues
    {
        $raw = $redis->get(self::OPTIONS);
        $options = is_string($raw) ? json_decode($raw, true) : null;
        $stored = is_array($options);

        if (! $stored) {
            $options = [];

            foreach (self::LEGACY_KEYS as $member => $key) {
                $value = $redis->get($key);

                if (is_string($value)) {
                    $options[$member] = $value;
                }
            }
        }

        return Fail2BanSettingsValues::fromStored($options, $this->fields($redis, self::ALLOWLIST), $this->fields($redis, self::DENYLIST), $stored);
    }

    /**
     * The stored rules in numeric key order; null when nothing (or no JSON object) is stored.
     *
     * @return list<string>|null
     *
     * @throws SharedStoreUnavailable
     */
    public function rules(?Redis $redis = null): ?array
    {
        $read = function (Redis $redis): ?array {
            $raw = $redis->get(self::RULES);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;

            if (! is_array($decoded)) {
                return null;
            }

            $rules = array_filter($decoded, fn (mixed $rule, int|string $key): bool => is_string($rule) && is_numeric($key), ARRAY_FILTER_USE_BOTH);
            uksort($rules, fn (int|string $a, int|string $b): int => (int) $a <=> (int) $b);

            return array_values(array_map('strval', $rules));
        };

        return $redis === null ? $this->store->run($read) : $read($redis);
    }

    /**
     * @return array<string, string> field => value
     *
     * @throws SharedStoreUnavailable
     */
    public function hash(string $key): array
    {
        return $this->store->run(function (Redis $redis) use ($key): array {
            $value = $redis->hGetAll($key);

            return is_array($value) ? array_map('strval', $value) : [];
        });
    }

    /**
     * @return list<string>
     */
    public function fields(Redis $redis, string $key): array
    {
        $value = $redis->hKeys($key);

        return is_array($value) ? array_values(array_map('strval', $value)) : [];
    }

    /** Removes the stored rules (rule reset). */
    public function deleteRules(): void
    {
        $this->store->run(fn (Redis $redis): mixed => $redis->del(self::RULES));
    }

    /**
     * An optimistic transaction over $keys. $plan reads (under WATCH) and decides; it returns the
     * writes to queue and the result, or throws to refuse (nothing is written). $record runs inside
     * a database transaction just before EXEC (the audit record); its failure discards the writes.
     *
     * @template T
     *
     * @param  list<string>  $keys
     * @param  Closure(Redis): array{0: Closure(Redis): mixed, 1: T}  $plan
     * @param  (Closure(T): mixed)|null  $record
     * @return array{0: T, 1: bool} the result, and whether the audit transaction committed
     *
     * @throws SharedStoreUnavailable
     */
    public function transaction(array $keys, Closure $plan, ?Closure $record = null): array
    {
        return $this->store->run(function (Redis $redis) use ($keys, $plan, $record): array {
            for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
                $redis->watch($keys);

                try {
                    [$writes, $result] = $plan($redis);

                    if ($this->afterRead !== null) {
                        $hook = $this->afterRead;
                        $this->afterRead = null;
                        $hook();
                    }
                } catch (Throwable $e) {
                    $redis->unwatch();

                    throw $e;
                }

                $multi = $redis->multi();
                $writes($multi);
                $committed = true;

                DB::beginTransaction();

                try {
                    if ($record !== null) {
                        $record($result);
                    }
                } catch (Throwable $e) {
                    DB::rollBack();
                    $redis->discard();

                    // The audit record could not be written: nothing is changed (research R24).
                    throw new ProblemException(ProblemType::TemporaryFailure, 'The audit log cannot be written, so nothing was changed; retry later.', ['dependency' => 'database'], ['Retry-After' => '5']);
                }

                $exec = $redis->exec();

                if (! is_array($exec)) {
                    DB::rollBack();

                    continue;
                }

                try {
                    DB::commit();
                } catch (Throwable) {
                    $committed = false;
                }

                return [$result, $committed];
            }

            throw new SharedStoreUnavailable('The ban service settings kept changing while they were saved.');
        });
    }
}
