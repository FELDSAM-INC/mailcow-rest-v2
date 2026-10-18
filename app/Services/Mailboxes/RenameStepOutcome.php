<?php

namespace App\Services\Mailboxes;

/**
 * The outcome of a rename step (contract `MailboxRenameStep.outcome`): `done`, `undone` (done, then
 * reverted), `notDone` (never started), `failed` (nothing changed by it), `unknown` (no answer within
 * the container API timeout), `undoFailed` (done, and reverting it failed) or `skipped`.
 */
enum RenameStepOutcome: string
{
    case Done = 'done';
    case Undone = 'undone';
    case NotDone = 'notDone';
    case Failed = 'failed';
    case Unknown = 'unknown';
    case UndoFailed = 'undoFailed';
    case Skipped = 'skipped';
}
