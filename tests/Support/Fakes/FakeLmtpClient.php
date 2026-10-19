<?php

namespace Tests\Support\Fakes;

use App\Clients\Lmtp\LmtpClient;
use App\Clients\Lmtp\LmtpResult;
use Closure;
use PHPUnit\Framework\Assert;
use RuntimeException;

/**
 * Records mass mail deliveries instead of making them (feature 013 T007). Every delivery succeeds
 * unless replies were programmed for the recipient: one reply per attempt in order, the last one
 * repeating (`delivered`, `temporaryFailure`, `permanentFailure`, `connectionFailure`, or `throw`
 * for an exception the client must never raise). onDeliver() runs a callback during each delivery,
 * for example to cancel the job while a message is in flight.
 */
final class FakeLmtpClient implements LmtpClient
{
    /** @var list<array{from: string, to: string, message: string}> */
    private array $deliveries = [];

    /** @var array<string, list<LmtpResult|string>> lower-cased address => replies */
    private array $replies = [];

    /** @var array<string, int> */
    private array $attempts = [];

    private ?Closure $during = null;

    public int $closed = 0;

    /**
     * The replies to the next attempts for an address, in order; the last one repeats. The string
     * `throw` makes the attempt throw.
     */
    public function replyTo(string $address, LmtpResult|string ...$replies): self
    {
        $this->replies[strtolower($address)] = array_values($replies);

        return $this;
    }

    /**
     * @param  Closure(string $to): void  $callback
     */
    public function onDeliver(Closure $callback): self
    {
        $this->during = $callback;

        return $this;
    }

    public function deliver(string $from, string $to, string $message): LmtpResult
    {
        $key = strtolower($to);
        $attempt = $this->attempts[$key] = ($this->attempts[$key] ?? 0) + 1;
        $this->deliveries[] = ['from' => $from, 'to' => $to, 'message' => $message];

        if ($this->during !== null) {
            ($this->during)($to);
        }

        $replies = $this->replies[$key] ?? [];
        $reply = $replies === [] ? LmtpResult::delivered() : $replies[min($attempt, count($replies)) - 1];

        if ($reply === 'throw') {
            throw new RuntimeException('The fake LMTP client was told to throw.');
        }

        return $reply instanceof LmtpResult ? $reply : LmtpResult::delivered();
    }

    public function close(): void
    {
        $this->closed++;
    }

    /**
     * Every attempt, in order.
     *
     * @return list<array{from: string, to: string, message: string}>
     */
    public function deliveries(): array
    {
        return $this->deliveries;
    }

    /** The number of attempts to deliver to an address. */
    public function attemptsFor(string $address): int
    {
        return $this->attempts[strtolower($address)] ?? 0;
    }

    public function assertNothingDelivered(): void
    {
        Assert::assertSame([], $this->deliveries, 'No message may be delivered.');
    }
}
