<?php

namespace Tests\Support\Dns;

use App\Clients\Dns\DnsResolver;
use App\Clients\Dns\DnsTemporaryFailure;
use PHPUnit\Framework\Assert;

/**
 * DNS for tests (feature 009 research R14): scripted records per name and type, names whose
 * lookups fail temporarily, and a log of every query, so that no test resolves a real name.
 * Unknown names have no records, as NXDOMAIN does.
 */
final class FakeDnsResolver implements DnsResolver
{
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
