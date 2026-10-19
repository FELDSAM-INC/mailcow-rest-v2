<?php

namespace Tests\Support\Mail;

use App\Clients\Mail\DeliveryOutcome;
use App\Clients\Mail\QuarantineReleaseMailer;
use PHPUnit\Framework\Assert;
use Symfony\Component\Mime\RawMessage;

/**
 * Records quarantine releases instead of delivering them to Postfix (feature 006 T012, research
 * R3): the envelope sender, the recipient and the exact bytes of each message. Releases are
 * accepted unless a test scripts other outcomes (refuse(), temporaryFailure()), consumed in order,
 * the last one repeating. delay() makes each delivery wait (concurrency and duration tests);
 * logTo() also appends each delivery to a file as a JSON line, so that the concurrency test can
 * count the deliveries of several processes. Bound for every test by Tests\TestCase.
 */
final class FakeQuarantineReleaseMailer implements QuarantineReleaseMailer
{
    /** @var list<array{sender: string, recipient: string, message: string}> */
    private array $deliveries = [];

    /** @var list<DeliveryOutcome> */
    private array $outcomes = [];

    private int $delayMs = 0;

    private ?string $logFile = null;

    public function deliver(RawMessage $message, string $sender, string $recipient): DeliveryOutcome
    {
        if ($this->delayMs > 0) {
            usleep($this->delayMs * 1000);
        }

        $outcome = match (count($this->outcomes)) {
            0 => DeliveryOutcome::accepted(),
            1 => $this->outcomes[0],
            default => array_shift($this->outcomes),
        };

        if ($outcome->isAccepted()) {
            $bytes = $message->toString();
            $this->deliveries[] = ['sender' => $sender, 'recipient' => $recipient, 'message' => $bytes];

            if ($this->logFile !== null) {
                file_put_contents($this->logFile, json_encode(['recipient' => $recipient, 'sha256' => hash('sha256', $bytes)])."\n", FILE_APPEND | LOCK_EX);
            }
        }

        return $outcome;
    }

    public function accept(): self
    {
        $this->outcomes = [];

        return $this;
    }

    public function refuse(int $code = 550, string $reply = '550 5.1.1 <gone@example.org>: Recipient address rejected: User unknown in virtual mailbox table'): self
    {
        $this->outcomes[] = DeliveryOutcome::refused($code, $reply);

        return $this;
    }

    public function temporaryFailure(string $reason = 'Connection to "postfix:590" timed out.'): self
    {
        $this->outcomes[] = DeliveryOutcome::temporaryFailure($reason);

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
     * @return list<array{sender: string, recipient: string, message: string}>
     */
    public function deliveries(): array
    {
        return $this->deliveries;
    }

    /**
     * @return array{sender: string, recipient: string, message: string}
     */
    public function lastDelivery(): array
    {
        Assert::assertNotEmpty($this->deliveries, 'Nothing was delivered.');

        return $this->deliveries[count($this->deliveries) - 1];
    }

    public function assertDelivered(int $count = 1): void
    {
        Assert::assertCount($count, $this->deliveries, 'Released messages: '.count($this->deliveries));
    }

    public function assertNothingDelivered(): void
    {
        $this->assertDelivered(0);
    }
}
