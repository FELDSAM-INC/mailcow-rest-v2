<?php

namespace Tests\Support\Rspamd;

use App\Clients\Rspamd\LearnOutcome;
use App\Clients\Rspamd\RspamdController;
use App\Clients\Rspamd\RspamdUnavailable;
use PHPUnit\Framework\Assert;
use Tests\Support\Monitoring\MonitoringFixtures;

/**
 * Records Rspamd controller calls instead of making them (feature 006 research R2; created by
 * feature 011 T008 with the reads 011 needs, extended by feature 006 T011 with the learning calls
 * and `actions()`).
 *
 *  - history() and stat() answer the recorded fixtures unless a test scripts other answers;
 *  - learnHam(), learnSpam() and fuzzyAdd() answer `done` unless respond() scripts outcomes for the
 *    endpoint (`/learnham`, `/learnspam`, `/fuzzyadd`), consumed in order, the last one repeating;
 *  - actions() answers mailcow's default thresholds unless withActions() scripts others;
 *  - unreachable() makes every later call fail like a closed socket (reads throw, learning calls
 *    answer `failed`).
 *
 * Every call is recorded with its endpoint, the `Flag` header of a fuzzy call and the SHA-256 of
 * the message, in order. logTo() also appends each learning call to a file as a JSON line, so that
 * the concurrency test can count the calls of several processes; delay() makes each call wait.
 */
final class FakeRspamdController implements RspamdController
{
    /** mailcow's default action thresholds (data/conf/rspamd/local.d/actions.conf). */
    public const DEFAULT_ACTIONS = [
        ['action' => 'reject', 'value' => 15],
        ['action' => 'add header', 'value' => 8],
        ['action' => 'greylist', 'value' => 7],
    ];

    /** @var list<array{endpoint: string, flag: int|null, sha256: string|null}> */
    private array $records = [];

    /** @var array<string, mixed>|null */
    private ?array $history = null;

    /** @var array<string, mixed>|null */
    private ?array $stat = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $actions = null;

    private bool $actionsUnreachable = false;

    /** @var array<string, list<LearnOutcome>> */
    private array $outcomes = [];

    private bool $unreachable = false;

    private int $delayMs = 0;

    private ?string $logFile = null;

    public function stat(?int $timeout = null): array
    {
        $this->record('/stat');
        $this->failIfUnreachable();

        return $this->stat ?? MonitoringFixtures::json('rspamd-stat.json');
    }

    public function history(): array
    {
        $this->record('/history');
        $this->failIfUnreachable();

        return $this->history ?? MonitoringFixtures::json('rspamd-history.json');
    }

    public function actions(): array
    {
        $this->record('/actions');
        $this->failIfUnreachable();

        if ($this->actionsUnreachable) {
            throw new RspamdUnavailable('The Rspamd controller is unreachable: connection refused');
        }

        return $this->actions ?? self::DEFAULT_ACTIONS;
    }

    public function learnHam(string $message): LearnOutcome
    {
        return $this->learn('/learnham', null, $message);
    }

    public function learnSpam(string $message): LearnOutcome
    {
        return $this->learn('/learnspam', null, $message);
    }

    public function fuzzyAdd(string $message, int $flag): LearnOutcome
    {
        return $this->learn('/fuzzyadd', $flag, $message);
    }

    /**
     * @param  array<string, mixed>  $history
     */
    public function withHistory(array $history): self
    {
        $this->history = $history;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $stat
     */
    public function withStat(array $stat): self
    {
        $this->stat = $stat;

        return $this;
    }

    /**
     * @param  list<array<string, mixed>>  $actions  the `GET /actions` document
     */
    public function withActions(array $actions): self
    {
        $this->actions = $actions;

        return $this;
    }

    /** Only `GET /actions` fails, as when the controller is down while scores are read. */
    public function actionsUnreachable(): self
    {
        $this->actionsUnreachable = true;

        return $this;
    }

    /**
     * Scripts the outcomes of a learning endpoint (`/learnham`, `/learnspam` or `/fuzzyadd`),
     * consumed in order; the last one repeats.
     */
    public function respond(string $endpoint, LearnOutcome ...$outcomes): self
    {
        $this->outcomes[$endpoint] = array_values($outcomes);

        return $this;
    }

    public function unreachable(): self
    {
        $this->unreachable = true;

        return $this;
    }

    public function delay(int $milliseconds): self
    {
        $this->delayMs = $milliseconds;

        return $this;
    }

    public function logTo(string $file): self
    {
        $this->logFile = $file;

        return $this;
    }

    /**
     * The endpoint of every call, for example `/history`.
     *
     * @return list<string>
     */
    public function calls(): array
    {
        return array_column($this->records, 'endpoint');
    }

    /**
     * Every call with its fuzzy flag and the SHA-256 of the message it carried.
     *
     * @return list<array{endpoint: string, flag: int|null, sha256: string|null}>
     */
    public function records(): array
    {
        return $this->records;
    }

    public function assertCalled(string $endpoint, int $times = 1): void
    {
        Assert::assertSame($times, count(array_keys($this->calls(), $endpoint, true)), "Rspamd {$endpoint} calls: ".json_encode($this->calls()));
    }

    /**
     * Asserts the exact sequence of learning and fuzzy calls: each entry is an endpoint, or
     * `[endpoint, flag]` for a fuzzy call; with $message, every call carried exactly that message.
     *
     * @param  list<string|array{0: string, 1: int}>  $expected
     */
    public function assertCalls(array $expected, ?string $message = null): void
    {
        $learning = array_values(array_filter($this->records, fn (array $r): bool => in_array($r['endpoint'], ['/learnham', '/learnspam', '/fuzzyadd'], true)));
        $actual = array_map(fn (array $r): string|array => $r['flag'] === null ? $r['endpoint'] : [$r['endpoint'], $r['flag']], $learning);

        Assert::assertSame($expected, $actual, 'Rspamd learning calls: '.json_encode($actual));

        if ($message !== null) {
            foreach ($learning as $record) {
                Assert::assertSame(hash('sha256', $message), $record['sha256'], "The message sent to {$record['endpoint']} differs.");
            }
        }
    }

    public function assertNoCalls(): void
    {
        $learning = array_filter($this->calls(), fn (string $e): bool => in_array($e, ['/learnham', '/learnspam', '/fuzzyadd'], true));

        Assert::assertSame([], array_values($learning), 'Rspamd learning calls: '.json_encode($this->calls()));
    }

    private function learn(string $endpoint, ?int $flag, string $message): LearnOutcome
    {
        $this->record($endpoint, $flag, $message);

        if ($this->unreachable) {
            return LearnOutcome::failed('cURL error 7: Failed to connect to /var/lib/rspamd/rspamd.sock: Connection refused');
        }

        $queue = $this->outcomes[$endpoint] ?? [];

        if ($queue === []) {
            return LearnOutcome::done();
        }

        $outcome = count($queue) > 1 ? array_shift($queue) : $queue[0];
        $this->outcomes[$endpoint] = $queue;

        return $outcome;
    }

    private function record(string $endpoint, ?int $flag = null, ?string $message = null): void
    {
        if ($this->delayMs > 0) {
            usleep($this->delayMs * 1000);
        }

        $record = ['endpoint' => $endpoint, 'flag' => $flag, 'sha256' => $message === null ? null : hash('sha256', $message)];
        $this->records[] = $record;

        if ($this->logFile !== null && $message !== null) {
            file_put_contents($this->logFile, json_encode($record)."\n", FILE_APPEND | LOCK_EX);
        }
    }

    private function failIfUnreachable(): void
    {
        if ($this->unreachable) {
            throw new RspamdUnavailable('The Rspamd controller is unreachable: connection refused');
        }
    }
}
