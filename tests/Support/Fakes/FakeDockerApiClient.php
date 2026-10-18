<?php

namespace Tests\Support\Fakes;

use App\Clients\DockerApi\DockerApiClient;
use App\Clients\DockerApi\DockerCallResult;
use PHPUnit\Framework\Assert;

/**
 * Records dockerapi calls instead of making them (feature 002 T023). Every call succeeds unless a
 * result was programmed with respondTo().
 */
final class FakeDockerApiClient implements DockerApiClient
{
    /** @var list<array{method: string, service: string, command: array<string, mixed>|null}> */
    private array $calls = [];

    /** @var array<string, DockerCallResult> */
    private array $results = [];

    /**
     * The result of every later call of a method (`restart` or `exec`).
     */
    public function respondTo(string $method, DockerCallResult $result): void
    {
        $this->results[$method] = $result;
    }

    public function restart(string $service): DockerCallResult
    {
        $this->calls[] = ['method' => 'restart', 'service' => $service, 'command' => null];

        return $this->results['restart'] ?? DockerCallResult::success();
    }

    public function exec(string $service, array $command): DockerCallResult
    {
        $this->calls[] = ['method' => 'exec', 'service' => $service, 'command' => $command];

        return $this->results['exec'] ?? DockerCallResult::success();
    }

    /**
     * @return list<array{method: string, service: string, command: array<string, mixed>|null}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function assertRestarted(string $service, int $times = 1): void
    {
        $count = count(array_filter($this->calls, fn (array $c): bool => $c['method'] === 'restart' && $c['service'] === $service));
        Assert::assertSame($times, $count, "{$service} was restarted {$count} times, expected {$times}.");
    }

    public function assertNotRestarted(): void
    {
        Assert::assertSame([], array_values(array_filter($this->calls, fn (array $c): bool => $c['method'] === 'restart')), 'A container was restarted.');
    }

    /**
     * @param  array<string, mixed>  $command
     */
    public function assertExecuted(string $service, array $command, int $times = 1): void
    {
        $count = count(array_filter($this->calls, fn (array $c): bool => $c['method'] === 'exec' && $c['service'] === $service && $c['command'] === $command));
        Assert::assertSame($times, $count, "The exec command was sent {$count} times, expected {$times}: ".json_encode($this->calls));
    }

    public function assertNothingCalled(): void
    {
        Assert::assertSame([], $this->calls, 'dockerapi was called.');
    }
}
