<?php

namespace Tests\Support\Fakes;

use App\Clients\Ispconfig\IspconfigDnsClient;
use App\Clients\Ispconfig\IspconfigException;
use App\Clients\Ispconfig\IspconfigRecord;
use App\Clients\Ispconfig\IspconfigZone;
use App\Support\Clock\Deadline;
use PHPUnit\Framework\Assert;
use Tests\Support\Dns\FakeClock;

/**
 * An in-memory ISPConfig DNS server (feature 002 T025, feature 010 T019): zones and records, a call
 * log with assertion helpers, programmable failures and delays (a delay advances the fake clock,
 * so the publication deadline applies as with the real adapter).
 */
final class FakeIspconfigDnsClient implements IspconfigDnsClient
{
    /** @var array<string, IspconfigZone> origin => zone */
    private array $zones = [];

    /** @var array<int, array<int, IspconfigRecord>> zone ID => record ID => record */
    private array $records = [];

    private int $nextRecordId = 100;

    /** @var list<array<string, mixed>> */
    private array $calls = [];

    /** @var array<string, array{string, string|null}> function => [reason, detail] */
    private array $failures = [];

    /** @var array<string, float> function => seconds */
    private array $delays = [];

    private ?Deadline $deadline = null;

    private bool $open = false;

    public function __construct(private readonly FakeClock $clock) {}

    public function zone(string $origin, int $id, int $sysGroupId): void
    {
        $this->zones[$origin] = new IspconfigZone($id, $origin, $sysGroupId);
        $this->records[$id] ??= [];
    }

    public function record(int $zoneId, string $name, string $type, string $data): int
    {
        $id = $this->nextRecordId++;
        $this->records[$zoneId][$id] = new IspconfigRecord($id, $name, $type, $data);

        return $id;
    }

    public function failOn(string $function, string $reason, ?string $detail = null): void
    {
        $this->failures[$function] = [$reason, $detail];
    }

    public function delay(string $function, float $seconds): void
    {
        $this->delays[$function] = $seconds;
    }

    public function open(Deadline $deadline): void
    {
        $this->deadline = $deadline;
        $this->call('login', []);
        $this->open = true;
    }

    public function findZone(string $origin): ?IspconfigZone
    {
        $this->call('dns_zone_get', ['origin' => $origin]);

        return $this->zones[$origin] ?? null;
    }

    public function records(int $zoneId): array
    {
        $this->call('dns_rr_get_all_by_zone', ['zoneId' => $zoneId]);

        return array_values($this->records[$zoneId] ?? []);
    }

    public function addTxt(int $clientId, array $params): void
    {
        $this->call('dns_txt_add', ['clientId' => $clientId, 'params' => $params]);
        $this->record((int) $params['zone'], (string) $params['name'], 'TXT', (string) $params['data']);
    }

    public function updateTxt(int $clientId, int $recordId, array $params): void
    {
        $this->call('dns_txt_update', ['clientId' => $clientId, 'recordId' => $recordId, 'params' => $params]);
        $zone = (int) $params['zone'];
        $this->records[$zone][$recordId] = new IspconfigRecord($recordId, (string) $params['name'], 'TXT', (string) $params['data']);
    }

    public function deleteTxt(int $recordId): void
    {
        $this->call('dns_txt_delete', ['recordId' => $recordId]);

        foreach ($this->records as $zone => $records) {
            unset($this->records[$zone][$recordId]);
        }
    }

    public function close(): void
    {
        if ($this->open) {
            $this->calls[] = ['function' => 'logout'];
            $this->open = false;
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function call(string $function, array $arguments): void
    {
        if ($this->deadline !== null && $this->deadline->remaining() < 0.5) {
            throw new IspconfigException('timeout', 'The publication time limit was reached.');
        }

        $this->calls[] = ['function' => $function, ...$arguments];
        $this->clock->advance($this->delays[$function] ?? 0.0);

        if (isset($this->failures[$function])) {
            [$reason, $detail] = $this->failures[$function];

            throw new IspconfigException($reason, $detail);
        }
    }

    /**
     * @return list<string>
     */
    public function functions(): array
    {
        return array_map(fn (array $call): string => (string) $call['function'], $this->calls);
    }

    /**
     * @return array<string, mixed>
     */
    public function lastCall(string $function): array
    {
        foreach (array_reverse($this->calls) as $call) {
            if ($call['function'] === $function) {
                return $call;
            }
        }

        Assert::fail("{$function} was not called: ".implode(', ', $this->functions()));
    }

    public function assertNoCalls(): void
    {
        Assert::assertSame([], $this->functions(), 'ISPConfig was called.');
    }

    public function assertCallCount(string $function, int $count): void
    {
        Assert::assertSame($count, count(array_keys($this->functions(), $function, true)), "{$function} call count");
    }

    public function assertRecord(int $zoneId, string $name, string $data): void
    {
        $matching = array_filter($this->records[$zoneId] ?? [], fn (IspconfigRecord $r): bool => strcasecmp($r->name, $name) === 0 && $r->type === 'TXT');

        Assert::assertCount(1, $matching, "Expected exactly one TXT record {$name} in zone {$zoneId}.");
        Assert::assertSame($data, array_values($matching)[0]->data);
    }
}
