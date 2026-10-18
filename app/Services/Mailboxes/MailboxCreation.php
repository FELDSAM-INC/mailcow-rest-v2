<?php

namespace App\Services\Mailboxes;

/**
 * A created mailbox: its address and the outcome of its follow-up steps.
 */
final readonly class MailboxCreation
{
    public function __construct(
        public string $address,
        public MailboxCreationReport $report,
    ) {}
}
