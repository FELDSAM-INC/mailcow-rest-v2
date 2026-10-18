<?php

namespace Tests\Support\Audit;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Models\AuditEvent;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use PDOException;

/**
 * An audit logger whose inserts fail with a refused connection, for the retry and refusal paths of
 * feature 011 (T013, T040, T065): selected attempts fail, or every insert of the given event
 * types. With $insertBeforeFailing the insert reaches the database before the error, as when an
 * answer is lost.
 */
class FlakyAuditLogger extends AuditLogger
{
    public int $attempts = 0;

    /**
     * @param  list<int>  $failingAttempts  1-based attempt numbers that fail
     * @param  list<AuditEventType>  $failingTypes  event types whose every insert fails
     */
    public function __construct(
        public array $failingAttempts = [],
        public bool $insertBeforeFailing = false,
        public array $failingTypes = [],
    ) {}

    public function recordWithId(?string $id, AuditEventType $type, bool $success, Principal|array|null $actor, ?array $target, ?string $authMethod, ?string $failureReason, array $details): AuditEvent
    {
        $this->attempts++;

        if (in_array($this->attempts, $this->failingAttempts, true) || in_array($type, $this->failingTypes, true)) {
            if ($this->insertBeforeFailing) {
                parent::recordWithId($id, $type, $success, $actor, $target, $authMethod, $failureReason, $details);
            }

            throw new QueryException('mysql', 'insert into `restv2_audit_events` ...', [], new PDOException('SQLSTATE[HY000] [2002] Connection refused'));
        }

        return parent::recordWithId($id, $type, $success, $actor, $target, $authMethod, $failureReason, $details);
    }
}
