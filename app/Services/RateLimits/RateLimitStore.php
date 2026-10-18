<?php

namespace App\Services\RateLimits;

use App\Clients\SharedStore\SharedStore;

/**
 * Rate limits in mailcow's `RL_VALUE` hash, read by Rspamd (feature 002 research D9): legacy
 * ratelimit('edit') writes `"<value> / 1<s|m|h|d>"`. Feature 006 extends this class with its
 * changes. Failures raise SharedStoreUnavailable.
 */
class RateLimitStore
{
    public const HASH = 'RL_VALUE';

    /** Contract frame => legacy unit. */
    public const FRAMES = ['second' => 's', 'minute' => 'm', 'hour' => 'h', 'day' => 'd'];

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
}
