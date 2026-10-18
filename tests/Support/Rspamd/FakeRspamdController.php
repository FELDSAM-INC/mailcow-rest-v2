<?php

namespace Tests\Support\Rspamd;

use App\Clients\Rspamd\RspamdController;
use App\Clients\Rspamd\RspamdUnavailable;
use PHPUnit\Framework\Assert;
use Tests\Support\Monitoring\MonitoringFixtures;

/**
 * Records Rspamd controller calls instead of making them (feature 006 research R2; created by
 * feature 011 T008 with the reads 011 needs). history() and stat() answer the recorded fixtures
 * unless a test scripts other answers; unreachable() makes every later call fail like a closed
 * socket. Feature 006 adds its learning calls.
 */
final class FakeRspamdController implements RspamdController
{
    /** @var list<string> endpoint of every call, for example `/history` */
    private array $calls = [];

    /** @var array<string, mixed>|null */
    private ?array $history = null;

    /** @var array<string, mixed>|null */
    private ?array $stat = null;

    private bool $unreachable = false;

    public function stat(): array
    {
        $this->calls[] = '/stat';
        $this->failIfUnreachable();

        return $this->stat ?? MonitoringFixtures::json('rspamd-stat.json');
    }

    public function history(): array
    {
        $this->calls[] = '/history';
        $this->failIfUnreachable();

        return $this->history ?? MonitoringFixtures::json('rspamd-history.json');
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

    public function unreachable(): self
    {
        $this->unreachable = true;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function assertCalled(string $endpoint, int $times = 1): void
    {
        Assert::assertSame($times, count(array_keys($this->calls, $endpoint, true)), "Rspamd {$endpoint} calls: ".json_encode($this->calls));
    }

    private function failIfUnreachable(): void
    {
        if ($this->unreachable) {
            throw new RspamdUnavailable('The Rspamd controller is unreachable: connection refused');
        }
    }
}
