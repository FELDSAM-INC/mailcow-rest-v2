<?php

namespace App\Clients\Dns;

use Closure;

/**
 * DnsResolver on PHP's dns_get_record() (feature 009 research R15), through the container's
 * resolver (mailcow's unbound in production, bounded by `dns_opt` in the deployment). PHP answers
 * an empty array for a name without records and `false` plus a warning ("A temporary server error
 * occurred.", "Dns Query failed") for a failure; the warning is captured here and never reaches
 * the log as a PHP warning.
 */
class NativeDnsResolver implements DnsResolver
{
    /** @var Closure(string, int): (array<int, mixed>|false) */
    private readonly Closure $lookup;

    /**
     * @param  (Closure(string, int): (array<int, mixed>|false))|null  $lookup  dns_get_record() unless given (tests)
     */
    public function __construct(?Closure $lookup = null)
    {
        $this->lookup = $lookup ?? fn (string $name, int $type): array|false => dns_get_record($name, $type);
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

    /**
     * @return list<array<string, mixed>>
     */
    private function query(string $name, int $type): array
    {
        $warning = null;
        set_error_handler(function (int $errno, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $result = ($this->lookup)($name, $type);
        } finally {
            restore_error_handler();
        }

        if ($result === false) {
            throw new DnsTemporaryFailure("The DNS lookup of {$name} failed temporarily".($warning === null ? '.' : ': '.preg_replace('/^dns_get_record\(\): /', '', $warning)));
        }

        return array_values(array_filter($result, 'is_array'));
    }
}
