<?php

namespace App\Services\DnsDiagnostics;

use App\Clients\Dns\DnsTemporaryFailure;
use App\Support\Clock\Clock;
use App\Support\Clock\Deadline;

/**
 * The time limits of one DNS diagnostics run (feature 010 FR-048, research R16):
 *
 *  - the run has a deadline of RESTV2_DNS_RUN_TIMEOUT (30 s);
 *  - a lookup starts only while at least RESTV2_DNS_LOOKUP_TIMEOUT (5 s) remain, otherwise it
 *    fails with `runTimeout` without asking the resolver;
 *  - dns_get_record() cannot be interrupted, so each lookup is bounded by the container's
 *    resolver options (`dns_opt` timeout:2 attempts:2, about 4 s); a failed lookup that lasted at
 *    least that bound is a `lookupTimeout`, a faster one a `lookupError` (SERVFAIL, which unbound
 *    also answers for a DNSSEC validation failure, REFUSED, a network error).
 *
 * Once the deadline is reached, every further lookup fails with `runTimeout`.
 */
final class LookupGuard
{
    /** Seconds after which a failed lookup counts as timed out: the resolver's own bound. */
    public const RESOLVER_BOUND_SECONDS = 4.0;

    private bool $stopped = false;

    public function __construct(
        private readonly Clock $clock,
        private readonly Deadline $deadline,
        private readonly int $lookupTimeout,
        private readonly int $runTimeout,
    ) {}

    /**
     * A guard for a run that starts now, with the configured limits.
     */
    public static function start(Clock $clock): self
    {
        $run = max(1, (int) config('restv2.dns.run_timeout', 30));

        return new self($clock, new Deadline($clock, $run), max(1, (int) config('restv2.dns.lookup_timeout', 5)), $run);
    }

    /**
     * Runs one lookup within the limits.
     *
     * @template T
     *
     * @param  callable(): T  $lookup
     * @return T
     *
     * @throws LookupFailed
     */
    public function run(callable $lookup): mixed
    {
        if ($this->stopped || $this->deadline->remaining() < $this->lookupTimeout) {
            $this->stopped = true;

            throw new LookupFailed(DnsCheckMessage::runTimeout($this->runTimeout));
        }

        $started = $this->clock->now();

        try {
            return $lookup();
        } catch (DnsTemporaryFailure) {
            $elapsed = ($this->clock->now()->getTimestampMs() - $started->getTimestampMs()) / 1000;

            throw new LookupFailed($elapsed >= self::RESOLVER_BOUND_SECONDS ? DnsCheckMessage::lookupTimeout($this->lookupTimeout) : DnsCheckMessage::lookupError());
        }
    }

    /** Whether the run's deadline stopped further lookups. */
    public function stopped(): bool
    {
        return $this->stopped;
    }
}
