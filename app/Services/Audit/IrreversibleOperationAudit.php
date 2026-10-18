<?php

namespace App\Services\Audit;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Exceptions\ProblemException;
use App\Services\Config\MailcowConfig;
use App\Support\Clock\Clock;
use App\Support\Clock\Deadline;
use App\Support\Clock\Sleeper;
use App\Support\Http\ProblemType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PDOException;
use Throwable;

/**
 * Audit records of irreversible operations (feature 011 research R24, FR-064): container actions,
 * queue actions and the ban rule reset cannot be undone, so the audit log is probed before they
 * start (`503 temporary-failure` when it cannot be read; nothing is done), and their record is
 * written afterwards with an ID chosen up front, retried every second until DOCKER_TIMEOUT has
 * passed since the operation started (for example while a restarted database comes back). A
 * duplicate ID on a retry means the earlier insert arrived. When the deadline passes, the failure
 * is logged at `error` level without details, and the response carries `Audit-Recorded: false`
 * (the response macro withAuditRecorded()).
 */
class IrreversibleOperationAudit
{
    public function __construct(
        private readonly AuditLogger $logger,
        private readonly Sleeper $sleeper,
        private readonly Clock $clock,
        private readonly MailcowConfig $config,
    ) {}

    /** DOCKER_TIMEOUT from now: the bound of the operation and of its record's retries. */
    public function deadline(): Deadline
    {
        return new Deadline($this->clock, (float) $this->config->dockerTimeout());
    }

    /**
     * @throws ProblemException `503 temporary-failure` when the audit log cannot be read
     */
    public function probe(): void
    {
        try {
            $this->probeQuery();
        } catch (Throwable $e) {
            Log::warning('The audit log cannot be read; the irreversible operation was refused: '.$e->getMessage());

            throw new ProblemException(ProblemType::TemporaryFailure, 'The audit log cannot be written, so the operation was not started. Nothing was changed.', [], ['Retry-After' => '5']);
        }
    }

    /**
     * Writes the record, retrying on database errors until the deadline. Returns whether it was
     * written.
     *
     * @param  array{type: string, id?: string|null, name?: string|null}|null  $target
     * @param  array<string, mixed>  $details
     */
    public function recordWithRetry(AuditEventType $type, bool $success, Principal $actor, ?array $target, ?string $failureReason, array $details, Deadline $deadline): bool
    {
        $id = (string) Str::ulid();

        while (true) {
            try {
                $this->logger->recordWithId($id, $type, $success, $actor, $target, null, $failureReason, $details);

                return true;
            } catch (QueryException|PDOException $e) {
                if (self::isDuplicate($e)) {
                    return true;
                }

                if ($deadline->expired()) {
                    Log::error("The audit record of {$type->value} could not be written; the operation was carried out without it.", [
                        'eventType' => $type->value,
                        'outcome' => $success ? 'success' : 'failure',
                        'target' => $target === null ? null : trim($target['type'].' '.($target['id'] ?? '')),
                        'failureReason' => $failureReason,
                    ]);

                    return false;
                }

                $this->sleeper->sleepSeconds(min(1.0, max(0.0, $deadline->remaining())));
                $this->reconnect();
            }
        }
    }

    /** One cheap read of the audit table. */
    protected function probeQuery(): void
    {
        DB::table('restv2_audit_events')->select(DB::raw('1'))->limit(1)->get();
    }

    /**
     * Reconnects after a lost connection. Inside a database transaction (tests) the connection is
     * kept, because reconnecting would end the transaction.
     */
    protected function reconnect(): void
    {
        if (DB::transactionLevel() === 0) {
            try {
                DB::reconnect();
            } catch (Throwable) {
                // The next attempt reports the failure again.
            }
        }
    }

    private static function isDuplicate(Throwable $e): bool
    {
        $code = $e instanceof QueryException ? ($e->errorInfo[1] ?? null) : null;

        return $code === 1062 || ($e->getCode() === '23000' && str_contains($e->getMessage(), 'Duplicate'));
    }
}
