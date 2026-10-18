<?php

namespace App\Services\Groupware;

/**
 * Outcome of a groupware step (directory update, restart) in feature 002's creation report.
 */
enum GroupwareOutcome: string
{
    case Done = 'done';
    case Failed = 'failed';
    case NotRequested = 'notRequested';
    case GroupwareDisabled = 'groupwareDisabled';
}
