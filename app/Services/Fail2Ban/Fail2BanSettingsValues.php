<?php

namespace App\Services\Fail2Ban;

/**
 * The ban service settings as the ban service uses them (feature 011 research R18): `F2B_OPTIONS`
 * (or the old separate keys) normalised like netfilter's verifyF2boptions() (numbers with `int()`,
 * an unusable value takes the default, flags by truthiness), the allowlist and denylist sorted
 * naturally, the ban list ID, and whether anything was stored.
 */
final readonly class Fail2BanSettingsValues
{
    public const DEFAULTS = [
        'ban_time' => 1800,
        'max_ban_time' => 10000,
        'ban_time_increment' => true,
        'max_attempts' => 10,
        'retry_window' => 600,
        'netban_ipv4' => 32,
        'netban_ipv6' => 128,
        'manage_external' => 0,
    ];

    /** Contract name => F2B_OPTIONS member of the numeric parameters. */
    public const NUMBERS = [
        'banTime' => 'ban_time',
        'maxBanTime' => 'max_ban_time',
        'maxAttempts' => 'max_attempts',
        'retryWindow' => 'retry_window',
        'ipv4PrefixLength' => 'netban_ipv4',
        'ipv6PrefixLength' => 'netban_ipv6',
    ];

    /**
     * @param  list<string>  $allowlist
     * @param  list<string>  $denylist
     */
    public function __construct(
        public int $banTime,
        public int $maxBanTime,
        public bool $banTimeIncrement,
        public int $maxAttempts,
        public int $retryWindow,
        public int $ipv4PrefixLength,
        public int $ipv6PrefixLength,
        public bool $externallyEnforced,
        public array $allowlist,
        public array $denylist,
        public ?string $banListId,
        public bool $stored,
    ) {}

    /**
     * @param  array<string, mixed>|null  $options  the decoded `F2B_OPTIONS`, or the old separate keys; null when nothing is stored
     * @param  list<string>  $allowlist
     * @param  list<string>  $denylist
     */
    public static function fromStored(?array $options, array $allowlist, array $denylist, bool $stored = true): self
    {
        $options ??= [];
        $number = fn (string $key): int => self::number($options[$key] ?? null, (int) self::DEFAULTS[$key]);
        $banListId = $options['banlist_id'] ?? null;

        return new self(
            banTime: $number('ban_time'),
            maxBanTime: $number('max_ban_time'),
            banTimeIncrement: array_key_exists('ban_time_increment', $options) && $options['ban_time_increment'] !== null ? self::truthy($options['ban_time_increment']) : true,
            maxAttempts: $number('max_attempts'),
            retryWindow: $number('retry_window'),
            ipv4PrefixLength: $number('netban_ipv4'),
            ipv6PrefixLength: $number('netban_ipv6'),
            externallyEnforced: in_array($options['manage_external'] ?? 0, [1, '1', true], true),
            allowlist: self::naturalSort($allowlist),
            denylist: self::naturalSort($denylist),
            banListId: is_scalar($banListId) && (string) $banListId !== '' ? (string) $banListId : null,
            stored: $stored,
        );
    }

    /**
     * A copy with other values (contract names, as the settings service changes them).
     *
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes): self
    {
        $values = get_object_vars($this);

        foreach ($changes as $name => $value) {
            $values[$name] = in_array($name, ['allowlist', 'denylist'], true) ? self::naturalSort((array) $value) : $value;
        }

        return new self(...$values);
    }

    /**
     * `F2B_OPTIONS` as legacy writes it: its key order and types (`ban_time_increment` boolean,
     * `manage_external` 0 or 1).
     */
    public function optionsJson(): string
    {
        return (string) json_encode([
            'ban_time' => $this->banTime,
            'ban_time_increment' => $this->banTimeIncrement,
            'max_ban_time' => $this->maxBanTime,
            'netban_ipv4' => $this->ipv4PrefixLength,
            'netban_ipv6' => $this->ipv6PrefixLength,
            'max_attempts' => $this->maxAttempts,
            'retry_window' => $this->retryWindow,
            'banlist_id' => $this->banListId,
            'manage_external' => $this->externallyEnforced ? 1 : 0,
        ]);
    }

    /** Python's `int()` of a stored value; the default when it does not convert. */
    private static function number(mixed $value, int $default): int
    {
        return match (true) {
            is_int($value) => $value,
            is_bool($value) => (int) $value,
            is_float($value) => (int) $value,
            is_string($value) && preg_match('/^\s*[+-]?\d+\s*$/', $value) === 1 => (int) $value,
            default => $default,
        };
    }

    /** Python truthiness of a decoded JSON value. */
    private static function truthy(mixed $value): bool
    {
        return ! in_array($value, [false, 0, 0.0, '', [], null], true);
    }

    /**
     * Unique entries in natural order (legacy natsort()).
     *
     * @param  array<mixed>  $entries
     * @return list<string>
     */
    private static function naturalSort(array $entries): array
    {
        $entries = array_values(array_unique(array_map('strval', $entries)));
        natsort($entries);

        return array_values($entries);
    }
}
