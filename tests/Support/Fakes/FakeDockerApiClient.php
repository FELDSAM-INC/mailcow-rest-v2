<?php

namespace Tests\Support\Fakes;

use App\Clients\DockerApi\DockerApiClient;
use App\Clients\DockerApi\DockerCallResult;
use Closure;
use PHPUnit\Framework\Assert;

/**
 * Records dockerapi calls instead of making them (feature 002 T023, extended by feature 003 T005).
 * Every call succeeds unless a result was programmed:
 *
 *  - respondTo(): the result of every later `restart` or `exec` call (feature 002);
 *  - script(): answers per `{service, cmd, task}`, one per call, the last one repeating;
 *  - aclList(): the data of `doveadm get_acl` queries; setReachable(): container lookups;
 *  - beforeAnswer(): a callback run before each exec or query is answered, for example to take a
 *    database snapshot.
 *
 * Broadcasts are logged, and the assertions check commands and their order.
 */
final class FakeDockerApiClient implements DockerApiClient
{
    /** @var list<array{method: string, service: string, command: array<string, mixed>|null}> */
    private array $calls = [];

    /** @var array<string, DockerCallResult> */
    private array $results = [];

    /** @var array<string, list<DockerCallResult>> */
    private array $scripts = [];

    /** @var list<array<string, mixed>> */
    private array $acl = [];

    /** @var array<string, bool> */
    private array $reachable = [];

    /** @var list<array{service: string, command: array<string, mixed>}> */
    private array $broadcasts = [];

    private ?Closure $beforeAnswer = null;

    /**
     * The result of every later call of a method (`restart` or `exec`).
     */
    public function respondTo(string $method, DockerCallResult $result): void
    {
        $this->results[$method] = $result;
    }

    /**
     * The answers to the next calls of one command, in order; the last answer repeats.
     */
    public function script(string $service, string $cmd, ?string $task, DockerCallResult ...$results): void
    {
        $this->scripts[self::key($service, $cmd, $task)] = array_values($results);
    }

    /**
     * The ACL entries `doveadm get_acl` answers: `{user, id, mailbox, rights}`.
     *
     * @param  list<array<string, mixed>>  $entries
     */
    public function aclList(array $entries): void
    {
        $this->acl = $entries;
    }

    public function setReachable(string $service, bool $reachable): void
    {
        $this->reachable[$service] = $reachable;
    }

    /**
     * @param  Closure(string, array<string, mixed>): void  $callback
     */
    public function beforeAnswer(Closure $callback): void
    {
        $this->beforeAnswer = $callback;
    }

    public function restart(string $service): DockerCallResult
    {
        $this->calls[] = ['method' => 'restart', 'service' => $service, 'command' => null];

        return $this->results['restart'] ?? DockerCallResult::success();
    }

    public function exec(string $service, array $command): DockerCallResult
    {
        $this->calls[] = ['method' => 'exec', 'service' => $service, 'command' => $command];

        return $this->answer($service, $command) ?? $this->results['exec'] ?? DockerCallResult::success();
    }

    public function query(string $service, array $command): DockerCallResult
    {
        $this->calls[] = ['method' => 'query', 'service' => $service, 'command' => $command];

        return $this->answer($service, $command) ?? DockerCallResult::success(null, $this->acl);
    }

    public function reachable(string $service): bool
    {
        return $this->reachable[$service] ?? true;
    }

    public function broadcast(string $service, array $command): void
    {
        $this->broadcasts[] = ['service' => $service, 'command' => $command];
    }

    /**
     * @return list<array{method: string, service: string, command: array<string, mixed>|null}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * The exec and query commands sent, in order.
     *
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    public function commands(): array
    {
        $commands = [];

        foreach ($this->calls as $call) {
            if ($call['command'] !== null) {
                $commands[] = [$call['service'], $call['command']];
            }
        }

        return $commands;
    }

    /**
     * @return list<array{service: string, command: array<string, mixed>}>
     */
    public function broadcasts(): array
    {
        return $this->broadcasts;
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
        $count = count(array_filter($this->calls, fn (array $c): bool => $c['command'] !== null && $c['service'] === $service && $c['command'] === $command));
        Assert::assertSame($times, $count, "The command was sent {$count} times, expected {$times}: ".json_encode($this->calls));
    }

    /**
     * @param  array<string, mixed>  $command
     */
    public function assertNotExecuted(string $service, array $command): void
    {
        $this->assertExecuted($service, $command, 0);
    }

    /**
     * The given commands were sent in this order (other commands may come in between).
     *
     * @param  list<array{0: string, 1: array<string, mixed>}>  $expected
     */
    public function assertExecOrder(array $expected): void
    {
        $next = 0;

        foreach ($this->commands() as $command) {
            if ($next < count($expected) && $command === $expected[$next]) {
                $next++;
            }
        }

        Assert::assertSame(count($expected), $next, 'The commands were not sent in the expected order: '.json_encode($this->commands()));
    }

    /**
     * @param  array<string, mixed>  $command
     */
    public function assertBroadcast(string $service, array $command, int $times = 1): void
    {
        $count = count(array_filter($this->broadcasts, fn (array $b): bool => $b['service'] === $service && $b['command'] === $command));
        Assert::assertSame($times, $count, "The command was broadcast {$count} times, expected {$times}: ".json_encode($this->broadcasts));
    }

    public function assertNothingCalled(): void
    {
        Assert::assertSame([], $this->calls, 'dockerapi was called.');
    }

    /**
     * @param  array<string, mixed>  $command
     */
    private function answer(string $service, array $command): ?DockerCallResult
    {
        if ($this->beforeAnswer !== null) {
            ($this->beforeAnswer)($service, $command);
        }

        $key = self::key($service, (string) ($command['cmd'] ?? ''), isset($command['task']) ? (string) $command['task'] : null);

        if (! isset($this->scripts[$key]) || $this->scripts[$key] === []) {
            return null;
        }

        return count($this->scripts[$key]) > 1 ? array_shift($this->scripts[$key]) : $this->scripts[$key][0];
    }

    private static function key(string $service, string $cmd, ?string $task): string
    {
        return $service.'|'.$cmd.'|'.($task ?? '');
    }
}
