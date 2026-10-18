<?php

namespace App\Services\Mailboxes;

use RuntimeException;

/**
 * A rename step that did not complete (internal to MailboxRenamer): the service it needed, and the
 * step's own outcome (`failed`, `unknown`, or `undoFailed` when the step could not revert its own
 * partial changes).
 */
final class RenameStepFailure extends RuntimeException
{
    public function __construct(
        public readonly string $dependency,
        string $message,
        public readonly RenameStepOutcome $outcome = RenameStepOutcome::Failed,
    ) {
        parent::__construct($message);
    }
}
