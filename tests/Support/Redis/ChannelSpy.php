<?php

namespace Tests\Support\Redis;

use PHPUnit\Framework\Assert;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Observes a Redis pub/sub channel (by default mailcow's F2B_CHANNEL) on the real Redis, so that
 * tests assert exactly what mailcow's fail2ban (netfilter) would receive (research R3, R18).
 *
 * It runs `redis-cli SUBSCRIBE` in a subprocess and waits for the subscription confirmation
 * before returning, so nothing published afterwards is missed. Pub/sub is not scoped to a
 * logical database.
 */
final class ChannelSpy
{
    private const WAIT_SECONDS = 1.0;

    private readonly Process $process;

    private string $buffer = '';

    /** @var list<string> */
    private array $messages = [];

    private bool $subscribed = false;

    public function __construct(string $host, int $port, ?string $password, private readonly string $channel = 'F2B_CHANNEL')
    {
        $command = ['redis-cli', '-h', $host, '-p', (string) $port, '-n', '0'];

        if ($password !== null && $password !== '') {
            $command = [...$command, '-a', $password, '--no-auth-warning'];
        }

        $this->process = new Process([...$command, 'SUBSCRIBE', $channel]);
        $this->process->setTimeout(null);
        $this->process->start();

        $deadline = microtime(true) + 5.0;

        while (! $this->subscribed) {
            $this->collect();

            if (! $this->process->isRunning()) {
                throw new RuntimeException('redis-cli SUBSCRIBE exited: '.$this->process->getErrorOutput().$this->process->getOutput());
            }

            if (microtime(true) > $deadline) {
                $this->stop();

                throw new RuntimeException("No subscription confirmation for {$channel}.");
            }

            usleep(10_000);
        }
    }

    /**
     * A spy on F2B_CHANNEL of the Redis that the `mailcow` connection uses.
     */
    public static function fail2ban(): self
    {
        $config = config('database.redis.mailcow');

        return new self((string) $config['host'], (int) $config['port'], $config['password'] ?? null);
    }

    /**
     * Messages received so far, waiting up to 1 s for at least $atLeast of them.
     *
     * @return list<string>
     */
    public function messages(int $atLeast = 0): array
    {
        $deadline = microtime(true) + self::WAIT_SECONDS;

        do {
            $this->collect();

            if (count($this->messages) >= $atLeast && $atLeast > 0) {
                break;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        return $this->messages;
    }

    public function assertPublished(string $exact): void
    {
        $deadline = microtime(true) + self::WAIT_SECONDS;

        do {
            $this->collect();

            if (in_array($exact, $this->messages, true)) {
                Assert::assertContains($exact, $this->messages);

                return;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        Assert::fail("Not published on {$this->channel}: {$exact}\nReceived: ".json_encode($this->messages));
    }

    public function assertNothingPublished(): void
    {
        Assert::assertSame([], $this->messages(), "Unexpected messages on {$this->channel}.");
    }

    public function stop(): void
    {
        if ($this->process->isRunning()) {
            $this->process->stop(0.2);
        }
    }

    public function __destruct()
    {
        $this->stop();
    }

    /**
     * Parses redis-cli's raw output: three lines per push (kind, channel, payload).
     */
    private function collect(): void
    {
        $this->buffer .= $this->process->getIncrementalOutput();

        while (substr_count($this->buffer, "\n") >= 3) {
            $lines = explode("\n", $this->buffer, 4);
            [$kind, , $payload] = $lines;
            $this->buffer = $lines[3];

            if ($kind === 'subscribe') {
                $this->subscribed = true;
            } elseif ($kind === 'message') {
                $this->messages[] = $payload;
            }
        }
    }
}
