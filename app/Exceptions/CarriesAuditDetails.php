<?php

namespace App\Exceptions;

/**
 * An exception whose failure record needs details that the rendered problem does not carry, for
 * example the steps of a mailbox rename or the outcome of a mail-store cleanup (feature 003). The
 * shared refusal auditing (RefusalAuditor, BulkActionRunner) merges them into the record's
 * `details`, filtered by the event type's documented keys, and also records such exceptions when
 * they end in a `5xx` answer. Details never hold secrets.
 */
interface CarriesAuditDetails
{
    /**
     * @return array<string, mixed>
     */
    public function auditDetails(): array;
}
