<?php

namespace App\Clients\Dns;

use Closure;

/**
 * DnsResolver on PHP's dns_get_record() (feature 009 research R15), through the container's
 * resolver (mailcow's unbound in production, bounded by `dns_opt` in the deployment). PHP answers
 * an empty array for a name without records and `false` plus a warning ("A temporary server error
 * occurred.", "Dns Query failed") for a failure; the warning is captured here and never reaches
 * the log as a PHP warning. TLSA (type 52), which PHP does not parse, is read in raw mode
 * (feature 010 research R16).
 */
class NativeDnsResolver implements DnsResolver
{
    /** The TLSA record type, queried in raw mode. */
    private const TYPE_TLSA = 52;

    /** @var Closure(string, int, bool): (array<int, mixed>|false) */
    private readonly Closure $lookup;

    /**
     * @param  (Closure(string, int, bool): (array<int, mixed>|false))|null  $lookup  dns_get_record() unless given (tests); the third argument asks for raw mode
     */
    public function __construct(?Closure $lookup = null)
    {
        $this->lookup = $lookup ?? function (string $name, int $type, bool $raw = false): array|false {
            $authoritative = null;
            $additional = null;

            return dns_get_record($name, $type, $authoritative, $additional, $raw);
        };
    }

    public function txt(string $name): array
    {
        $records = [];

        foreach ($this->query($name, DNS_TXT) as $record) {
            if (isset($record['entries']) && is_array($record['entries'])) {
                $records[] = implode('', array_map('strval', $record['entries']));
            } elseif (isset($record['txt'])) {
                $records[] = (string) $record['txt'];
            }
        }

        return $records;
    }

    public function mx(string $name): array
    {
        $records = [];

        foreach ($this->query($name, DNS_MX) as $record) {
            if (isset($record['target'])) {
                $records[] = ['host' => strtolower(rtrim((string) $record['target'], '.')), 'preference' => (int) ($record['pri'] ?? 0)];
            }
        }

        return $records;
    }

    public function a(string $name): array
    {
        return array_values(array_map(fn (array $r): string => (string) $r['ip'], array_filter($this->query($name, DNS_A), fn (array $r): bool => isset($r['ip']))));
    }

    public function aaaa(string $name): array
    {
        return array_values(array_map(fn (array $r): string => (string) $r['ipv6'], array_filter($this->query($name, DNS_AAAA), fn (array $r): bool => isset($r['ipv6']))));
    }

    public function cname(string $name): array
    {
        return array_values(array_map(fn (array $r): string => self::host((string) $r['target']), array_filter($this->query($name, DNS_CNAME), fn (array $r): bool => isset($r['target']))));
    }

    public function srv(string $name): array
    {
        $records = [];

        foreach ($this->query($name, DNS_SRV) as $record) {
            if (array_key_exists('target', $record)) {
                $target = self::host((string) $record['target']);
                $records[] = ['target' => $target === '' ? '.' : $target, 'port' => (int) ($record['port'] ?? 0), 'priority' => (int) ($record['pri'] ?? 0), 'weight' => (int) ($record['weight'] ?? 0)];
            }
        }

        return $records;
    }

    public function ptr(string $name): array
    {
        return array_values(array_map(fn (array $r): string => self::host((string) $r['target']), array_filter($this->query($name, DNS_PTR), fn (array $r): bool => isset($r['target']))));
    }

    public function tlsa(string $name): array
    {
        $records = [];

        foreach ($this->query($name, self::TYPE_TLSA, true) as $record) {
            $data = (string) ($record['data'] ?? '');

            if (strlen($data) > 3) {
                $records[] = ord($data[0]).' '.ord($data[1]).' '.ord($data[2]).' '.bin2hex(substr($data, 3));
            }
        }

        return $records;
    }

    private static function host(string $host): string
    {
        return strtolower(rtrim($host, '.'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function query(string $name, int $type, bool $raw = false): array
    {
        $warning = null;
        set_error_handler(function (int $errno, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $result = $raw ? ($this->lookup)($name, $type, true) : ($this->lookup)($name, $type);
        } finally {
            restore_error_handler();
        }

        if ($result === false) {
            throw new DnsTemporaryFailure("The DNS lookup of {$name} failed temporarily".($warning === null ? '.' : ': '.preg_replace('/^dns_get_record\(\): /', '', $warning)));
        }

        return array_values(array_filter($result, 'is_array'));
    }
}
