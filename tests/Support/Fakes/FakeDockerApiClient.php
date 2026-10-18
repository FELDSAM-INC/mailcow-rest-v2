<?php

namespace Tests\Support\Fakes;

use App\Clients\DockerApi\ContainerSnapshot;
use App\Clients\DockerApi\DockerApiClient;
use App\Clients\DockerApi\DockerApiTimeout;
use App\Clients\DockerApi\DockerApiUnreachable;
use App\Clients\DockerApi\DockerCallResult;
use Carbon\CarbonImmutable;
use Closure;
use PHPUnit\Framework\Assert;
use Tests\Support\Monitoring\MonitoringFixtures;

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
 *
 * Feature 011 (T007): the fake keeps the containers of mailcow's compose project (by default the
 * recorded `tests/fixtures/monitoring/containers.json`, parsed like the HTTP adapter, so
 * environments are dropped) and changes their state on start (running), stop (exited) and restart
 * (running with a new start time); resistActions() keeps a container in its state for a number of
 * attempts. Host figures default to the recorded `host-stats.json`, stamped with the current
 * (travelled) time unless a test sets `system_time`. withExec() scripts text and `{type, msg}`
 * answers per service, `cmd` and `task`, and withQueue() simulates Postfix's queue behind the
 * `mailq` tasks. failNext(), failCall() and failAlways() make calls fail as unreachable, as a
 * timeout, or with dockerapi's `danger` message.
 */
final class FakeDockerApiClient implements DockerApiClient
{
    /** @var list<array{method: string, service: string, command: array<string, mixed>|null}> */
    private array $calls = [];

    /** @var array<string, DockerCallResult> */
    private array $results = [];

    /** @var array<string, list<DockerCallResult>> */
    private array $scripts = [];

    /** @var list<array<mixed>> */
    private array $acl = [];

    /** @var array<string, bool> */
    private array $reachable = [];

    /** @var list<array{service: string, command: array<string, mixed>}> */
    private array $broadcasts = [];

    private ?Closure $beforeAnswer = null;

    /** @var array<string, ContainerSnapshot>|null service => container; null: the recorded fixture */
    private ?array $containers = null;

    /** @var array<string, mixed>|null */
    private ?array $hostStats = null;

    private bool $stampHostTime = true;

    /** @var array<string, list<array<string, mixed>>> */
    private array $stats = [];

    /** @var array<string, DockerCallResult|string|Closure|null> "<service> <cmd> <task>" => answer */
    private array $execAnswers = [];

    /** @var array<string, array<int, string>> method => call number => failure mode */
    private array $failures = [];

    /** @var array<string, string> method => failure mode of every call */
    private array $permanentFailures = [];

    /** @var array<string, int> service => attempts that start or stop ignore */
    private array $resist = [];

    /** @var array<string, Closure(): void> service => callback run when the service is restarted */
    private array $onRestart = [];

    /** @var list<array<string, mixed>>|null the simulated Postfix queue */
    private ?array $queue = null;

    private string $postfix = 'postfix-mailcow';

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
     * @param  list<array<mixed>>  $entries
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

        if (($failure = $this->failure('restart')) !== null) {
            return $this->actionFailure($failure);
        }

        $result = $this->results['restart'] ?? DockerCallResult::success();

        if ($result->isSuccess()) {
            $this->transition($service, true, true);

            if (isset($this->onRestart[$service])) {
                ($this->onRestart[$service])();
            }
        }

        return $result;
    }

    public function exec(string $service, array $command): DockerCallResult
    {
        $this->calls[] = ['method' => 'exec', 'service' => $service, 'command' => $command];

        if (($failure = $this->failure('exec')) !== null) {
            return $this->actionFailure($failure);
        }

        $scripted = $this->answer($service, $command);

        if ($scripted !== null) {
            return $scripted;
        }

        $answer = $this->answerFor($service, $command);

        return $answer !== false ? $this->toResult($answer, false) : $this->results['exec'] ?? DockerCallResult::success();
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

    public function execText(string $service, array $command): DockerCallResult
    {
        $this->calls[] = ['method' => 'execText', 'service' => $service, 'command' => $command];

        if (($failure = $this->failure('execText')) !== null) {
            return $this->actionFailure($failure);
        }

        $scripted = $this->answer($service, $command);

        if ($scripted !== null) {
            return $scripted;
        }

        $answer = $this->answerFor($service, $command);

        return $answer === false ? DockerCallResult::success(null, '') : $this->toResult($answer, true);
    }

    public function containers(): array
    {
        $this->record('containers', '');
        $this->readFailure('containers');

        return array_values($this->state());
    }

    public function start(string $service): DockerCallResult
    {
        $this->record('start', $service);

        if (($failure = $this->failure('start')) !== null) {
            return $this->actionFailure($failure);
        }

        $this->transition($service, true, false);

        return DockerCallResult::success('command completed successfully');
    }

    public function stop(string $service): DockerCallResult
    {
        $this->record('stop', $service);

        if (($failure = $this->failure('stop')) !== null) {
            return $this->actionFailure($failure);
        }

        $this->transition($service, false, false);

        return DockerCallResult::success('command completed successfully');
    }

    public function containerStats(string $service, float $timeout = 5.0): array
    {
        $this->record('containerStats', $service);
        $this->readFailure('containerStats');

        return $this->stats[$service] ?? [];
    }

    public function hostStats(): array
    {
        $this->record('hostStats', '');
        $this->readFailure('hostStats');

        $stats = $this->hostStats ?? MonitoringFixtures::hostStats();

        if ($this->stampHostTime) {
            $stats['system_time'] = CarbonImmutable::now()->setTimezone((string) config('restv2.timezone', 'UTC'))->format('d.m.Y H:i:s');
        }

        return $stats;
    }

    // ---- Feature 011 scripting --------------------------------------------------------------

    /**
     * The containers dockerapi reports, as its raw `GET /containers/json?all=true` answer (a map of
     * ID to `docker inspect`); parsed and filtered by compose project like the HTTP adapter.
     *
     * @param  array<mixed>  $answer
     */
    public function withContainers(array $answer): self
    {
        $this->containers = [];

        foreach (ContainerSnapshot::listForProject($answer, (string) config('restv2.compose_project_name')) as $container) {
            $this->containers[$container->service] = $container;
        }

        return $this;
    }

    /**
     * Changes a container's state without any call (as if it changed outside the API).
     */
    public function setRunning(string $service, bool $running, ?string $startedAt = null): self
    {
        $current = $this->state()[$service] ?? null;
        Assert::assertNotNull($current, "No container {$service} in the fake.");
        $this->containers[$service] = new ContainerSnapshot(
            $current->id,
            $service,
            $current->image,
            $running ? 'running' : 'exited',
            $running,
            $startedAt ?? $current->startedAt,
            $current->health,
        );

        return $this;
    }

    public function container(string $service): ?ContainerSnapshot
    {
        return $this->state()[$service] ?? null;
    }

    /**
     * Host figures; with $stampTime the fake sets `system_time` to the current (travelled) time.
     *
     * @param  array<string, mixed>  $stats
     */
    public function withHostStats(array $stats, bool $stampTime = false): self
    {
        $this->hostStats = $stats;
        $this->stampHostTime = $stampTime;

        return $this;
    }

    /**
     * @param  list<array<string, mixed>>  $samples
     */
    public function withStats(string $service, array $samples): self
    {
        $this->stats[$service] = $samples;

        return $this;
    }

    /**
     * The answer of an exec command: a DockerCallResult, a string (a text answer, or dockerapi's
     * `{type: success}` for exec()), null (dockerapi's `null`: the container is not running), or a
     * closure receiving the command and returning one of these.
     */
    public function withExec(string $service, string $cmd, string $task, DockerCallResult|string|Closure|null $answer): self
    {
        $this->execAnswers["{$service} {$cmd} {$task}"] = $answer;

        return $this;
    }

    /**
     * Simulates Postfix's queue behind the `mailq` tasks of $service: `list` prints it as
     * `postqueue -j`, `cat` answers `postcat -q`, `hold` and `unhold` move messages, `delete` and
     * `super_delete` remove them, `deliver` and `flush` keep them (delivery happens later).
     */
    public function withQueue(string $postqueueOutput, string $service = 'postfix-mailcow'): self
    {
        $this->postfix = $service;
        $this->queue = [];

        foreach (preg_split('/\R/', $postqueueOutput) ?: [] as $line) {
            $message = json_decode($line, true);

            if (is_array($message)) {
                $this->queue[] = $message;
            }
        }

        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function queue(): array
    {
        return $this->queue ?? [];
    }

    /**
     * Makes the next call of a method fail: `unreachable`, `timeout` or `danger:<message>`.
     */
    public function failNext(string $method, string $mode): self
    {
        return $this->failCall($method, $this->callCount($method) + 1, $mode);
    }

    /**
     * Makes the $nth call of a method (counted from the first call of the test) fail.
     */
    public function failCall(string $method, int $nth, string $mode): self
    {
        $this->failures[$method][$nth] = $mode;

        return $this;
    }

    /** Makes every later call of a method fail. */
    public function failAlways(string $method, string $mode): self
    {
        $this->permanentFailures[$method] = $mode;

        return $this;
    }

    /**
     * Runs a callback when the service is restarted successfully, for example the ban service
     * storing its default rules.
     *
     * @param  Closure(): void  $callback
     */
    public function onRestart(string $service, Closure $callback): self
    {
        $this->onRestart[$service] = $callback;

        return $this;
    }

    /** start and stop leave the container unchanged for the next $attempts calls. */
    public function resistActions(string $service, int $attempts): self
    {
        $this->resist[$service] = $attempts;

        return $this;
    }

    /**
     * @return list<array{method: string, service: string, command: array<string, mixed>|null}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function callCount(string $method, ?string $service = null): int
    {
        return count(array_filter($this->calls, fn (array $c): bool => $c['method'] === $method && ($service === null || $c['service'] === $service)));
    }

    public function assertCalled(string $method, ?string $service = null): void
    {
        Assert::assertGreaterThan(0, $this->callCount($method, $service), "dockerapi {$method}(".($service ?? '').') was not called: '.json_encode($this->calls));
    }

    public function assertNotCalled(string $method): void
    {
        Assert::assertSame(0, $this->callCount($method), "dockerapi {$method}() was called: ".json_encode($this->calls));
    }

    public function assertCallCount(string $method, int $count, ?string $service = null): void
    {
        Assert::assertSame($count, $this->callCount($method, $service), "dockerapi {$method}() calls: ".json_encode($this->calls));
    }

    /** No start, stop or restart was sent. */
    public function assertNoAction(): void
    {
        foreach (['start', 'stop', 'restart'] as $method) {
            $this->assertNotCalled($method);
        }
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

    // ---- Feature 011 internals --------------------------------------------------------------

    /**
     * @return array<string, ContainerSnapshot>
     */
    private function state(): array
    {
        if ($this->containers === null) {
            $this->withContainers(MonitoringFixtures::containers());
        }

        return $this->containers ?? [];
    }

    private function record(string $method, string $service): void
    {
        $this->calls[] = ['method' => $method, 'service' => $service, 'command' => null];
    }

    private function failure(string $method): ?string
    {
        return $this->failures[$method][$this->callCount($method)] ?? $this->permanentFailures[$method] ?? null;
    }

    private function readFailure(string $method): void
    {
        $failure = $this->failure($method);

        match (true) {
            $failure === null => null,
            $failure === 'timeout' => throw new DockerApiTimeout('The container service did not answer in time.'),
            default => throw new DockerApiUnreachable('The container service is unreachable: '.$failure),
        };
    }

    private function actionFailure(string $mode): DockerCallResult
    {
        return match (true) {
            $mode === 'timeout' => DockerCallResult::unknown('The container service did not answer in time.'),
            str_starts_with($mode, 'danger:') => DockerCallResult::failed(substr($mode, 7)),
            default => DockerCallResult::unreachable('The container service is unreachable: connection refused'),
        };
    }

    private function transition(string $service, bool $running, bool $restart): void
    {
        $current = $this->state()[$service] ?? null;

        if ($current === null) {
            return;
        }

        if (! $restart && ($this->resist[$service] ?? 0) > 0) {
            $this->resist[$service]--;

            return;
        }

        $startedAt = $running && ($restart || ! $current->running)
            ? CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z')
            : $current->startedAt;

        $this->containers[$service] = new ContainerSnapshot($current->id, $service, $current->image, $running ? 'running' : 'exited', $running, $startedAt, $current->health);
    }

    /**
     * @param  array<string, mixed>  $command
     * @return DockerCallResult|string|null|false false when nothing is scripted
     */
    private function answerFor(string $service, array $command): DockerCallResult|string|null|false
    {
        $key = $service.' '.($command['cmd'] ?? '').' '.($command['task'] ?? '');

        if (array_key_exists($key, $this->execAnswers)) {
            $answer = $this->execAnswers[$key];

            return $answer instanceof Closure ? $answer($command) : $answer;
        }

        if ($this->queue !== null && $service === $this->postfix && ($command['cmd'] ?? null) === 'mailq') {
            return $this->queueAnswer($command);
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $command
     */
    private function queueAnswer(array $command): ?string
    {
        if (! ($this->state()[$this->postfix]->running ?? false)) {
            return null;
        }

        $ids = array_map('strval', (array) ($command['items'] ?? []));
        $task = (string) ($command['task'] ?? '');

        if ($task === 'list') {
            return implode("\n", array_map(fn (array $m): string => (string) json_encode($m, JSON_UNESCAPED_SLASHES), $this->queue ?? []))."\n";
        }

        if ($task === 'cat') {
            $id = $ids[0] ?? '';

            return in_array($id, array_column($this->queue ?? [], 'queue_id'), true)
                ? str_replace('4F0A1C2B3D', $id, MonitoringFixtures::text('postcat-ok.txt'))
                : str_replace('4F0A1C2B3D', $id, MonitoringFixtures::text('postcat-missing.txt'));
        }

        foreach ($this->queue ?? [] as $i => $message) {
            $selected = in_array((string) $message['queue_id'], $ids, true);

            match (true) {
                $task === 'hold' && $selected => $this->queue[$i]['queue_name'] = 'hold',
                $task === 'unhold' && $selected && $message['queue_name'] === 'hold' => $this->queue[$i]['queue_name'] = 'deferred',
                ($task === 'delete' && $selected) || $task === 'super_delete' => $this->queue[$i] = null,
                default => null,
            };
        }

        $this->queue = array_values(array_filter($this->queue ?? []));

        return 'success';
    }

    private function toResult(DockerCallResult|string|null $answer, bool $text): DockerCallResult
    {
        return match (true) {
            $answer instanceof DockerCallResult => $answer,
            $answer === null => DockerCallResult::failed($text ? 'The container service returned no answer: the container is not running.' : 'HTTP 200'),
            $text => DockerCallResult::success(null, $answer),
            default => DockerCallResult::success('command completed successfully'),
        };
    }
}
