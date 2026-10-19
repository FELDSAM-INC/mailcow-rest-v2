<?php

namespace App\Services\RateLimits;

use App\Clients\SharedStore\SharedStore;
use App\Support\Redis\LuaScripts;
use Redis;
use UnexpectedValueException;

/**
 * Rate limits in mailcow's `RL_VALUE` hash, read by Rspamd (feature 002 research D9): legacy
 * ratelimit('edit') writes `"<value> / 1<s|m|h|d>"`. Failures raise SharedStoreUnavailable.
 *
 * Feature 006 (research R12) adds the raw reads, the classification of a stored value (a limit, or
 * an invalid text written by another tool) and compare-and-set writes: a Lua script compares the
 * stored value with the one the caller read (or its absence) before `HSET` or `HDEL`, so that a
 * change made meanwhile is never overwritten. This class stays the only writer of `RL_VALUE`.
 */
class RateLimitStore
{
    public const HASH = 'RL_VALUE';

    /** Contract frame => legacy unit. */
    public const FRAMES = ['second' => 's', 'minute' => 'm', 'hour' => 'h', 'day' => 'd'];

    /** The largest limit (FR-053). */
    public const MAX_VALUE = 2147483647;

    public function __construct(private readonly SharedStore $store) {}

    public function set(string $object, int $value, string $frame): void
    {
        $this->store->setFields(self::HASH, [$object => self::format($value, $frame)]);
    }

    /**
     * @return array{value: int, frame: string}|null
     */
    public function get(string $object): ?array
    {
        return self::parse($this->store->getField(self::HASH, $object));
    }

    /**
     * @param  list<string>  $objects
     * @return array<string, array{value: int, frame: string}|null>
     */
    public function getMany(array $objects): array
    {
        return array_map(fn (?string $raw): ?array => self::parse($raw), $this->store->getFields(self::HASH, $objects));
    }

    public function delete(string $object): void
    {
        $this->store->deleteFields([self::HASH => [$object]]);
    }

    /** The stored text of an object's limit, or null when none is set. */
    public function raw(string $object): ?string
    {
        return $this->store->getField(self::HASH, $object);
    }

    /**
     * Every stored limit as it is stored.
     *
     * @return array<string, string> object => stored text
     */
    public function all(): array
    {
        $fields = $this->store->run(fn (Redis $redis): mixed => $redis->hGetAll(self::HASH));
        $all = [];

        foreach (is_array($fields) ? $fields : [] as $object => $value) {
            $all[(string) $object] = (string) $value;
        }

        return $all;
    }

    /**
     * Sets an object's limit when its stored text is still $expected (null: not set).
     */
    public function setIfMatches(string $object, ?string $expected, string $value): RateLimitWrite
    {
        return $this->compareAndSet($object, $expected, $value);
    }

    /**
     * Removes an object's limit when its stored text is still $expected (null: not set, which
     * changes nothing).
     */
    public function removeIfMatches(string $object, ?string $expected): RateLimitWrite
    {
        return $this->compareAndSet($object, $expected, null);
    }

    public static function format(int $value, string $frame): string
    {
        return $value.' / 1'.(self::FRAMES[$frame] ?? $frame);
    }

    /**
     * `10 / 1h` => `{value: 10, frame: hour}`; anything else, or a value below 1, is no rate limit.
     *
     * @return array{value: int, frame: string}|null
     */
    public static function parse(?string $raw): ?array
    {
        if ($raw === null || preg_match('/^(\d+) \/ 1([smhd])$/', trim($raw), $m) !== 1 || (int) $m[1] < 1) {
            return null;
        }

        return ['value' => (int) $m[1], 'frame' => (string) array_search($m[2], self::FRAMES, true)];
    }

    /**
     * A stored text as feature 006 reports it (FR-050, FR-056): a limit in the exact legacy format
     * (`<1–2147483647> / 1<s|m|h|d>`), or the text itself as `invalidStoredValue`; null: not set.
     *
     * @return array{limit: array{value: int, frame: string}|null, invalidStoredValue: string|null}
     */
    public static function classify(?string $raw): array
    {
        if ($raw === null) {
            return ['limit' => null, 'invalidStoredValue' => null];
        }

        if (preg_match('/^([1-9][0-9]{0,9}) \/ 1([smhd])$/D', $raw, $m) === 1 && (int) $m[1] <= self::MAX_VALUE) {
            return ['limit' => ['value' => (int) $m[1], 'frame' => (string) array_search($m[2], self::FRAMES, true)], 'invalidStoredValue' => null];
        }

        return ['limit' => null, 'invalidStoredValue' => $raw];
    }

    private function compareAndSet(string $object, ?string $expected, ?string $value): RateLimitWrite
    {
        $result = $this->store->evaluate(LuaScripts::COMPARE_AND_SET_FIELD, [self::HASH], [
            $object,
            $expected === null ? '0' : '1',
            $expected ?? '',
            $value === null ? '0' : '1',
            $value ?? '',
        ]);

        return match ($result) {
            1 => RateLimitWrite::Changed,
            2 => RateLimitWrite::Unchanged,
            0 => RateLimitWrite::Outdated,
            default => throw new UnexpectedValueException('The rate limit script answered '.var_export($result, true).'.'),
        };
    }
}
