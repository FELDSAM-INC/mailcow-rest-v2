<?php

namespace Tests\Support\Dns;

use App\Clients\Dns\DnsResolver;
use App\Clients\Dns\DnsTemporaryFailure;
use PHPUnit\Framework\Assert;

/**
 * DNS for tests (feature 009 research R14): scripted records per name and type, names whose
 * lookups fail temporarily, and a log of every query, so that no test resolves a real name.
 * Unknown names have no records, as NXDOMAIN does. Feature 010 (T048) adds CNAME, SRV (a null
 * record has the target `.`), PTR and TLSA answers, and a delay per lookup that advances the fake
 * clock (before the answer or the failure), so that time limits can be tested.
 */
final class FakeDnsResolver implements DnsResolver
{
    /** @var array<string, float> name ('' for every name) => seconds per lookup */
    private array $delays = [];

    public function __construct(private readonly ?FakeClock $clock = null) {}

    /** @var array<string, array<string, list<mixed>>> type => name => records */
    private array $records = [];

    /** @var array<string, list<string>> name => failing types (empty: every type) */
    private array $failing = [];

    /** @var list<array{type: string, name: string}> */
    private array $queries = [];

    /**
     * TXT records of a name, each one a string (its character strings already concatenated).
     */
    public function txtRecords(string $name, string ...$records): self
    {
        $this->records['TXT'][self::key($name)] = array_values($records);

        return $this;
    }

    /**
     * MX records of a name, in the order the resolver returns them.
     *
     * @param  array<string, int>  $exchangers  host => preference
     */
    public function mxRecords(string $name, array $exchangers): self
    {
        $records = [];

        foreach ($exchangers as $host => $preference) {
            $records[] = ['host' => (string) $host, 'preference' => $preference];
        }

        $this->records['MX'][self::key($name)] = $records;

        return $this;
    }

    public function aRecords(string $name, string ...$addresses): self
    {
        $this->records['A'][self::key($name)] = array_values($addresses);

        return $this;
    }

    public function aaaaRecords(string $name, string ...$addresses): self
    {
        $this->records['AAAA'][self::key($name)] = array_values($addresses);

        return $this;
    }

    /**
     * CNAME targets of a name.
     */
    public function cnameRecords(string $name, string ...$targets): self
    {
        $this->records['CNAME'][self::key($name)] = array_values($targets);

        return $this;
    }

    /**
     * SRV records of a name as [target, port] or [target, port, priority, weight]; `.` is a null
     * record.
     *
     * @param  list<array{0: string, 1: int, 2?: int, 3?: int}>  $records
     */
    public function srvRecords(string $name, array $records): self
    {
        $this->records['SRV'][self::key($name)] = array_map(fn (array $r): array => [
            'target' => $r[0], 'port' => $r[1], 'priority' => $r[2] ?? 0, 'weight' => $r[3] ?? 1,
        ], $records);

        return $this;
    }

    public function ptrRecords(string $name, string ...$hosts): self
    {
        $this->records['PTR'][self::key($name)] = array_values($hosts);

        return $this;
    }

    /**
     * TLSA records as `<usage> <selector> <matching type> <hex data>`.
     */
    public function tlsaRecords(string $name, string ...$records): self
    {
        $this->records['TLSA'][self::key($name)] = array_values($records);

        return $this;
    }

    /**
     * Every lookup (of the name, or of every name) takes the given seconds on the fake clock.
     */
    public function delay(float $seconds, ?string $name = null): self
    {
        $this->delays[$name === null ? '' : self::key($name)] = $seconds;

        return $this;
    }

    public function cname(string $name): array
    {
        /** @var list<string> */
        return $this->lookup('CNAME', $name);
    }

    public function srv(string $name): array
    {
        /** @var list<array{target: string, port: int, priority: int, weight: int}> */
        return $this->lookup('SRV', $name);
    }

    public function ptr(string $name): array
    {
        /** @var list<string> */
        return $this->lookup('PTR', $name);
    }

    public function tlsa(string $name): array
    {
        /** @var list<string> */
        return $this->lookup('TLSA', $name);
    }

    /**
     * Lookups of the name fail temporarily (SERVFAIL, timeout): for the given types, or all.
     */
    public function failing(string $name, string ...$types): self
    {
        $this->failing[self::key($name)] = array_values($types);

        return $this;
    }

    public function txt(string $name): array
    {
        /** @var list<string> */
        return $this->lookup('TXT', $name);
    }

    public function mx(string $name): array
    {
        /** @var list<array{host: string, preference: int}> */
        return $this->lookup('MX', $name);
    }

    public function a(string $name): array
    {
        /** @var list<string> */
        return $this->lookup('A', $name);
    }

    public function aaaa(string $name): array
    {
        /** @var list<string> */
        return $this->lookup('AAAA', $name);
    }

    /**
     * @return list<array{type: string, name: string}>
     */
    public function queries(): array
    {
        return $this->queries;
    }

    public function lookupCount(?string $type = null): int
    {
        return count(array_filter($this->queries, fn (array $q): bool => $type === null || $q['type'] === $type));
    }

    public function assertQueried(string $type, string $name): void
    {
        Assert::assertContains(['type' => $type, 'name' => self::key($name)], $this->queries, "No {$type} lookup of {$name} was made.");
    }

    public function assertNotQueried(string $type, ?string $name = null): void
    {
        foreach ($this->queries as $query) {
            if ($query['type'] === $type && ($name === null || $query['name'] === self::key($name))) {
                Assert::fail("An unexpected {$type} lookup of {$query['name']} was made.");
            }
        }

        Assert::assertNotContains(['type' => $type, 'name' => $name === null ? '' : self::key($name)], $this->queries);
    }

    public function assertNothingQueried(): void
    {
        Assert::assertSame([], $this->queries, 'DNS was queried.');
    }

    /**
     * @return list<mixed>
     */
    private function lookup(string $type, string $name): array
    {
        $key = self::key($name);
        $this->queries[] = ['type' => $type, 'name' => $key];
        $delay = $this->delays[$key] ?? $this->delays[''] ?? 0.0;

        if ($delay > 0 && $this->clock !== null) {
            $this->clock->advance($delay);
        }

        if (isset($this->failing[$key]) && ($this->failing[$key] === [] || in_array($type, $this->failing[$key], true))) {
            throw new DnsTemporaryFailure("The {$type} lookup of {$name} failed temporarily.");
        }

        return $this->records[$type][$key] ?? [];
    }

    private static function key(string $name): string
    {
        return strtolower(rtrim($name, '.'));
    }
}
